<?php
/**
 * Handles REST API requests for the plugin.
 *
 * This class provides methods for handling REST API requests for logs and other admin functionality.
 *
 * @since      1.0.0
 */
class FDS_REST_Controller {

    /**
     * The database instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_DB    $db    The database instance.
     */
    protected $db;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    The logger instance.
     */
    protected $logger;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->db = new FDS_DB();
        $this->logger = new FDS_Logger();
        
        // AJAX handlers
        add_action('wp_ajax_fds_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_fds_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_fds_oauth_disconnect', array($this, 'ajax_oauth_disconnect'));
        add_action('wp_ajax_fds_get_sync_stats', array($this, 'ajax_get_sync_stats'));
        add_action('wp_ajax_fds_force_process_queue', array($this, 'ajax_force_process_queue'));
        add_action('wp_ajax_fds_retry_failed_tasks', array($this, 'ajax_retry_failed_tasks'));
    }

    /**
     * Get logs via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_get_logs() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'filebird-dropbox-sync')));
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Get parameters
        $level = isset($_POST['level']) ? sanitize_text_field($_POST['level']) : 'error';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 20;
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get logs
        $logs = $this->db->get_logs($level, $per_page, $offset);
        
        // Get total count for pagination
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_logs';
        
        $levels = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug');
        $level_index = array_search($level, $levels);
        
        if ($level_index !== false) {
            $included_levels = array_slice($levels, 0, $level_index + 1);
            $placeholders = implode(',', array_fill(0, count($included_levels), '%s'));
            $where = $wpdb->prepare("WHERE level IN ($placeholders)", $included_levels);
        } else {
            $where = "";
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
        
        // Format logs for response
        $formatted_logs = array();
        
        if (is_array($logs)) {
            foreach ($logs as $log) {
                $formatted_logs[] = array(
                    'id' => $log->id,
                    'level' => $log->level,
                    'message' => $log->message,
                    'context' => $log->context,
                    'created_at' => $log->created_at,
                );
            }
        } else {
            // Add a log entry to help with debugging
            $this->logger->error("Failed to retrieve logs", [
                'level' => $level,
                'page' => $page,
                'per_page' => $per_page
            ]);
            
            // Force add a log entry to the database
            $this->db->add_log('error', 'Logs retrieval system test', [
                'timestamp' => current_time('mysql')
            ]);
        }
        
        wp_send_json_success(array(
            'logs' => $formatted_logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ));
    }

    /**
     * Clear logs via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_clear_logs() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'filebird-dropbox-sync')));
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Clear logs
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            wp_send_json_success(array('message' => __('Logs cleared successfully.', 'filebird-dropbox-sync')));
        } else {
            wp_send_json_error(array('message' => __('Failed to clear logs.', 'filebird-dropbox-sync')));
        }
    }

    /**
     * Disconnect from Dropbox via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_oauth_disconnect() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'filebird-dropbox-sync')));
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Clear Dropbox tokens
        delete_option('fds_dropbox_access_token');
        delete_option('fds_dropbox_refresh_token');
        delete_option('fds_dropbox_token_expiry');
        
        // Disable sync
        update_option('fds_sync_enabled', false);
        
        // Try to unregister webhook
        $dropbox_api = new FDS_Dropbox_API(new FDS_Settings());
        try {
            $dropbox_api->unregister_webhook();
            $this->logger->info('Unregistered Dropbox webhook');
        } catch (Exception $e) {
            $this->logger->notice('Failed to unregister Dropbox webhook', array('exception' => $e->getMessage()));
        }
        
        $this->logger->info('Disconnected from Dropbox');
        
        wp_send_json_success(array('message' => __('Successfully disconnected from Dropbox.', 'filebird-dropbox-sync')));
    }

    /**
     * Get synchronization statistics via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_get_sync_stats() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        global $wpdb;
        
        // Get file mapping count
        $file_table = $wpdb->prefix . 'fds_file_mapping';
        $total_files = $wpdb->get_var("SELECT COUNT(*) FROM $file_table");
        
        // Get synced files count (files with a recent sync date)
        $synced_files = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $file_table WHERE last_synced > DATE_SUB(NOW(), INTERVAL %d HOUR)",
                24 // Consider files synced in last 24 hours
            )
        );
        
        // Get queue stats
        $queue_table = $wpdb->prefix . 'fds_sync_queue';
        $pending_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'pending'");
        $failed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'");
        
        wp_send_json_success([
            'total_files' => intval($total_files),
            'synced_files' => intval($synced_files),
            'pending_tasks' => intval($pending_tasks),
            'failed_tasks' => intval($failed_tasks)
        ]);
    }

    /**
     * Force processing of sync queue via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_force_process_queue() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        $queue = new FDS_Queue(
            new FDS_Folder_Sync(new FDS_Dropbox_API(new FDS_Settings()), new FDS_DB(), new FDS_Logger()),
            new FDS_File_Sync(new FDS_Dropbox_API(new FDS_Settings()), new FDS_DB(), new FDS_Logger()),
            new FDS_Logger()
        );
        
        $processed = $queue->force_process_queue();
        
        wp_send_json_success([
            'message' => sprintf(__('Successfully processed %d tasks.', 'filebird-dropbox-sync'), $processed),
            'processed' => $processed
        ]);
    }

    /**
     * Retry failed tasks via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_retry_failed_tasks() {
        // Check permissions
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'fds_sync_queue';
        
        // Update all failed tasks to pending and reset attempts
        $updated = $wpdb->query(
            "UPDATE $queue_table 
            SET status = 'pending', attempts = 0, error_message = '', updated_at = NOW() 
            WHERE status = 'failed'"
        );
        
        wp_send_json_success([
            'message' => sprintf(__('Reset %d failed tasks to pending status.', 'filebird-dropbox-sync'), $updated),
            'updated' => $updated
        ]);
    }
}