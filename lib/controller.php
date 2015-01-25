<?php
/**
 * Controller object manages tree retrieval, manipulation and publishing
 */
class WordPress_GitHub_Sync_Controller {

  /**
   * Instantiates a new Controller object
   *
   * $posts - array of post IDs to export
   */
  function __construct() {
    $this->api = new WordPress_GitHub_Sync_Api;
    $this->changed = false;
    $this->posts = array();
    $this->tree = array();
  }

  /**
   * Reads the Webhook payload and syncs posts as necessary
   */
  function pull($payload) {
    if ( strtolower($payload->repository->full_name) !== strtolower($this->api->repository()) ) {
      WordPress_GitHub_Sync::write_log( $nwo . __(" is an invalid repository.", WordPress_GitHub_Sync::$text_domain) );
      return;
    }

    $refs = explode('/', $payload->ref);
    $branch = array_pop( $refs );

    if ( 'master' === $branch ) {
      WordPress_GitHub_Sync::write_log( __("Not on the master branch.", WordPress_GitHub_Sync::$text_domain) );
      return;
    }

    $this->import_head_commit($payload->head_commit);

    // Deleting posts from a payload is the only place
    // we need to search posts by path; another way?
    $removed = array();
    foreach ($payload->commits as $commit) {
      $removed  = array_merge( $removed,  $commit->removed  );
    }
    foreach (array_unique($removed) as $path) {
      $post = new WordPress_GitHub_Sync_Post($path);
      wp_delete_post($post->id);
    }
  }

  /**
   * Updates all posts that need updating from the head commit
   */
  function import_head_commit($head_commit) {
    if ( "wpghs" === substr( $head_commit->message, -5 ) ) {
      WordPress_GitHub_Sync::write_log( __("Already synced this commit.", WordPress_GitHub_Sync::$text_domain) );
      return;
    }

    $commit = $this->api->get_commit($head_commit->id);

    $tree = $this->api->get_tree_recursive($commit->tree->sha);

    foreach ($tree as $blob) {
      // Skip the repo's readme
      if ( 'readme' === strtolower(substr($blob->path, 0, 6)) ) {
        continue;
      }

      // If the blob sha already matches a post, then move on
      $id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sha' AND meta_value = '$blob->sha'");
       if ( $id ) {
        continue;
       }

      $blob = $this->api->get_blob($blob->sha);
      $content = base64_decode($blob->content);

      // If it doesn't have YAML frontmatter, then move on
      if ( '---' !== substr($content, 0, 3) ) {
        continue;
      }

      // Break out meta, if present
      preg_match( "/(^---(.*?)---$)?(.*)/ms", $content, $matches );

      $body = array_pop( $matches );

      if (count($matches) == 3) {
        $meta = spyc_load($matches[2]);
        if ($meta['permalink']) $meta['permalink'] = str_replace(home_url(), '', get_permalink($meta['permalink']));
      } else {
        $meta = array();
      }

      if ( function_exists( 'wpmarkdown_markdown_to_html' ) ) {
        $body = wpmarkdown_markdown_to_html( $body );
      }

      // Can we really just mash everything together here?
      wp_update_post( array_merge( $meta, array(
        "post_content" => $body,
        "_sha"         => $blob->sha,
      ) ) );
    }
  }

  /**
   * Export all the posts in the database to GitHub
   */
  function export_all() {
    global $wpdb;

    if ( $this->locked() ) {
      return;
    }

    $posts = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type IN ('post', 'page' )" );
    $this->msg = "Full export from WordPress at " . site_url() . " (" . get_bloginfo( 'name' ) . ") - wpghs";

    $this->get_tree();

    WordPress_GitHub_Sync::write_log( __("Building the tree.", WordPress_GitHub_Sync::$text_domain ) );
    foreach ($posts as $post_id) {
      $post = new WordPress_GitHub_Sync_Post($post_id);
      $this->post_to_tree($post);
    }

    $this->finalize();
  }

  /**
   * Exports a single post to GitHub by ID
   */
  function export_post($post_id) {
    if ( $this->locked() ) {
      return;
    }

    $post = new WordPress_GitHub_Sync_Post($post_id);
    $this->msg = "Syncing " . $post->github_path() . " from WordPress at " . site_url() . " (" . get_bloginfo( 'name' ) . ") - wpghs";

    $this->get_tree();

    WordPress_GitHub_Sync::write_log( __("Building the tree.", WordPress_GitHub_Sync::$text_domain ) );
    $this->post_to_tree($post);

    $this->finalize();
  }

  /**
   * Removes the post from the tree
   */
  function delete_post($post_id) {
    if ( $this->locked() ) {
      return;
    }

    $post = new WordPress_GitHub_Sync_Post($post_id);
    $this->msg = "Deleting " . $post->github_path() . " via WordPress at " . site_url() . " (" . get_bloginfo( 'name' ) . ") - wpghs";

    $this->get_tree();

    WordPress_GitHub_Sync::write_log( __("Building the tree.", WordPress_GitHub_Sync::$text_domain ) );

    $this->post_to_tree($post, true);

    $this->finalize();
  }

  /**
   * Takes the next post off the top of the list
   * and exports it to the new GitHub tree
   */
  function post_to_tree($post, $remove = false) {
    $match = false;

    foreach ($this->tree as $index => $blob) {
      if ( !isset($blob->sha)) {
        continue;
      }

      if ( $blob->sha === $post->sha() ) {
        unset($this->tree[ $index ]);
        $match = true;

        if ( ! $remove ) {
          $this->tree[] = $this->new_blob($post, $blob);
        } else {
          $this->changed = true;
        }

        break;
      }
    }

    if ( ! $match ) {
      $this->tree[] = $this->new_blob($post);
      $this->changed = true;
    }
  }

  /**
   * After all the blobs are saved,
   * create the tree, commit, and adjust master ref
   */
  function finalize() {
    if ( ! $this->changed ) {
      $this->no_change();
      return;
    }

    WordPress_GitHub_Sync::write_log(__( 'Creating the tree.', WordPress_GitHub_Sync::$text_domain ));
    $tree = $this->api->create_tree(array_values($this->tree));

    if ( is_wp_error( $tree ) ) {
      $this->error($tree);
      return;
    }

    $rtree = $this->api->last_tree_recursive();

    WordPress_GitHub_Sync::write_log(__( 'Saving the shas.', WordPress_GitHub_Sync::$text_domain ));
    $this->save_post_shas($rtree);

    WordPress_GitHub_Sync::write_log(__( 'Creating the commit.', WordPress_GitHub_Sync::$text_domain ));
    $commit = $this->api->create_commit($tree->sha, $this->msg);

    if ( is_wp_error( $commit ) ) {
      $this->error($commit);
      return;
    }

    WordPress_GitHub_Sync::write_log(__( 'Setting the master branch to our new commit.', WordPress_GitHub_Sync::$text_domain ));
    $ref = $this->api->set_ref($commit->sha);

    if ( is_wp_error( $ref ) ) {
      $this->error($ref);
      return;
    }

    $this->success();
  }

  /**
   * Combines a post and (potentially) a blob
   *
   * If no blob is provided, turns post into blob
   *
   * If blob is provided, compares blob to post
   * and updates blob data based on differences
   */
  function new_blob($post, $blob = array()) {
    if ( empty($blob) ) {
      $blob = $this->blob_from_post($post);
    } else {
      unset($blob->url);
      unset($blob->size);

      if ( $blob->path !== $post->github_path()) {
        $blob->path = $post->github_path();
        $this->changed = true;
      }

      $blob_data = $this->api->get_blob($blob->sha);

      if ( base64_decode($blob_data->content) !== $post->github_content() ) {
        unset($blob->sha);
        $blob->content = $post->github_content();
        $this->changed = true;
      }
    }

    return $blob;
  }

  /**
   * Creates a blob with the data required for the tree
   */
  function blob_from_post($post) {
    $blob = new stdClass;

    $blob->path = $post->github_path();
    $blob->mode = "100644";
    $blob->type = "blob";
    $blob->content = $post->github_content();

    return $blob;
  }

  /**
   * Use the new tree to save sha data
   * for all the updated posts
   */
  function save_post_shas($tree) {
    foreach ($this->posts as $post_id) {
      $post = new WordPress_GitHub_Sync_Post($post_id);
      $match = false;

      foreach ($tree as $blob) {
        // this might be a problem if the filename changed since it was set
        // (i.e. post updated in middle mass export)
        // solution?
        if ($post->github_path() === $blob->path) {
          $post->set_sha($blob->sha);
          $match = true;
          break;
        }
      }

      if ( ! $match ) {
        WordPress_GitHub_Sync::write_log( __('No sha matched for post ID ', WordPress_GitHub_Sync::$text_domain ) . $post_id);
      }
    }
  }

  /**
   * Check if we're clear to call the api
   */
  function locked() {
    global $wpghs;

    if (! $this->api->oauth_token() || ! $this->api->repository() || $wpghs->push_lock) {
      return true;
    }

    return false;
  }

  /**
   * Writes out the results of an unchanged export
   */
  function no_change() {
    update_option( '_wpghs_export_complete', 'yes' );
    WordPress_GitHub_Sync::write_log( __('There were no changes, so no additional commit was added.', WordPress_GitHub_Sync::$text_domain ), 'warning' );
  }

  /**
   * Writes out the results of a successful export
   */
  function success() {
    update_option( '_wpghs_export_complete', 'yes' );
    update_option( '_wpghs_fully_exported', 'yes' );
    WordPress_GitHub_Sync::write_log( __('Export to GitHub completed successfully.', WordPress_GitHub_Sync::$text_domain ), 'success' );
  }

  /**
   * Writes out the results of an error and saves the data
   */
  function error($result) {
    update_option( '_wpghs_export_error', $result->get_error_message() );
    WordPress_GitHub_Sync::write_log( __("Error exporting to GitHub. Error: ", WordPress_GitHub_Sync::$text_domain ) . $result->get_error_message(), 'error' );
  }

  /**
   * Retrieve the saved tree we're building
   * or get the latest tree from the repo
   */
  function get_tree() {
    if ( ! empty($this->tree) ) {
      return;
    }

    $this->tree = $this->api->last_tree_recursive();
  }
}