<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @since      1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define the tables to be dropped
global $wpdb;
$tables = array(
    $wpdb->prefix . 'fds_folder_mapping',
    $wpdb->prefix . 'fds_file_mapping',
    $wpdb->prefix . 'fds_sync_queue',
    $wpdb->prefix . 'fds_logs',
);

// Drop the tables
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Delete all options
$options = array(
    'fds_sync_enabled',
    'fds_conflict_resolution',
    'fds_root_dropbox_folder',
    'fds_dropbox_app_key',
    'fds_dropbox_app_secret',
    'fds_dropbox_access_token',
    'fds_dropbox_refresh_token',
    'fds_dropbox_token_expiry',
    'fds_queue_batch_size',
    'fds_max_retries',
    'fds_log_level',
    'fds_dropbox_cursor',
    'fds_webhook_challenge',
    'fds_oauth_csrf_token',
);

foreach ($options as $option) {
    delete_option($option);
}

// Remove temporary directory
$upload_dir = wp_upload_dir();
$fds_dir = $upload_dir['basedir'] . '/fds-temp';

if (file_exists($fds_dir) && is_dir($fds_dir)) {
    // Remove all files in the directory
    $files = glob($fds_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    
    // Remove the directory
    @rmdir($fds_dir);
}

// Remove capabilities
$admin_role = get_role('administrator');
if ($admin_role) {
    $admin_role->remove_cap('manage_fds_settings');
}

// Clear scheduled events
wp_clear_scheduled_hook('fds_process_queue');