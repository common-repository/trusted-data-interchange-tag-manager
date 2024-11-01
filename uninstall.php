<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit();
}

$options = array('wordpress_mezzobit_tag_manager_container_id', 'wordpress_mezzobit_tag_manager_container_descr', 'wordpress_mezzobit_tag_manager_access_token');

if (is_multisite()) {
  global $wpdb;
  $blog_ids = $wpdb->get_col('SELECT blog_id FROM ' . $wpdb->blogs);
  $original_blog_id = get_current_blog_id();
  foreach ($blog_ids as $blog_id) {
    switch_to_blog($blog_id);
    foreach ($options as $option) {
      delete_site_option($option);
    }
  }
  switch_to_blog($original_blog_id);
} else {
  foreach ($options as $option) {
    delete_option($option);
  }
}
