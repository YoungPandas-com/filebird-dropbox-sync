<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 */
class FDS_Deactivator {

    /**
     * Clean up scheduled events and any temporary files.
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Clear scheduled cron events
        $timestamp = wp_next_scheduled('fds_process_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fds_process_queue');
        }
        
        // Attempt to unregister webhook with Dropbox
        // This requires the access token to be valid which may not be the case
        // So we'll try, but won't force it
        $dropbox_api = new FDS_Dropbox_API(new FDS_Settings());
        if ($dropbox_api->has_valid_token()) {
            try {
                $dropbox_api->unregister_webhook();
            } catch (Exception $e) {
                // Just log, don't block deactivation
                error_log('Failed to unregister Dropbox webhook: ' . $e->getMessage());
            }
        }
        
        // Clear any temporary files in our directory
        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-temp';
        
        if (file_exists($fds_dir) && is_dir($fds_dir)) {
            $files = glob($fds_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.htaccess' && basename($file) !== 'index.php') {
                    @unlink($file);
                }
            }
        }
        
        // We don't delete database tables or settings to prevent data loss
        // These will be cleaned up during uninstall if the user chooses to delete
    }
}