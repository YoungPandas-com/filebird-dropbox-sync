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
     * The queue instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Queue    $queue    The queue instance.
     */
    protected $queue;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    FDS_DB      $db      The database instance (optional).
     * @param    FDS_Logger  $logger  The logger instance (optional).
     * @param    FDS_Queue   $queue   The queue instance (optional).
     */
    public function __construct($db = null, $logger = null, $queue = null) {
        // Initialize properties safely
        $this->db = $db ?: new FDS_DB();
        $this->logger = $logger ?: new FDS_Logger();
        $this->queue = $queue;
        
        // Register REST endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // AJAX handlers
        add_action('wp_ajax_fds_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_fds_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_fds_oauth_disconnect', array($this, 'ajax_oauth_disconnect'));
        add_action('wp_ajax_fds_get_sync_stats', array($this, 'ajax_get_sync_stats'));
        add_action('wp_ajax_fds_force_process_queue', array($this, 'ajax_force_process_queue'));
        add_action('wp_ajax_fds_retry_failed_tasks', array($this, 'ajax_retry_failed_tasks'));
        add_action('wp_ajax_fds_dismiss_welcome_notice', array($this, 'ajax_dismiss_welcome_notice'));
        add_action('wp_ajax_fds_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_fds_check_sync_status', array($this, 'ajax_check_sync_status'));
    }

    /**
     * Set the queue instance.
     *
     * @since    1.0.0
     * @param    FDS_Queue    $queue    The queue instance.
     */
    public function set_queue($queue) {
        $this->queue = $queue;
    }

    /**
     * Register REST API routes.
     *
     * @since    1.0.0
     */
    public function register_rest_routes() {
        register_rest_route('fds/v1', '/logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_logs'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('fds/v1', '/logs', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'rest_clear_logs'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('fds/v1', '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_sync_stats'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('fds/v1', '/process-queue', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_force_process_queue'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('fds/v1', '/retry-failed', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_retry_failed_tasks'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
    }

    /**
     * Check if user has admin permission.
     *
     * @since    1.0.0
     * @return   bool   True if user has permission, false otherwise.
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Get logs via REST API.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function rest_get_logs($request) {
        $level = $request->get_param('level') ?? 'error';
        $page = max(1, $request->get_param('page') ?? 1);
        $per_page = max(1, $request->get_param('per_page') ?? 20);
        
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
        }
        
        return new WP_REST_Response(array(
            'logs' => $formatted_logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ), 200);
    }

    /**
     * Clear logs via REST API.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function rest_clear_logs($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            return new WP_REST_Response(array(
                'message' => __('Logs cleared successfully.', 'filebird-dropbox-sync')
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'message' => __('Failed to clear logs.', 'filebird-dropbox-sync')
            ), 500);
        }
    }

    /**
     * Get sync stats via REST API.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function rest_get_sync_stats($request) {
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
        $processing_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'processing'");
        $failed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'");
        $completed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'");
        
        // Check if there's a queue lock (processing in progress)
        $is_processing = get_transient('fds_queue_lock') ? true : false;
        
        return new WP_REST_Response(array(
            'total_files' => intval($total_files),
            'synced_files' => intval($synced_files),
            'pending_tasks' => intval($pending_tasks),
            'processing_tasks' => intval($processing_tasks),
            'failed_tasks' => intval($failed_tasks),
            'completed_tasks' => intval($completed_tasks),
            'is_processing' => $is_processing,
            'last_updated' => current_time('mysql')
        ), 200);
    }

    /**
     * Force process queue via REST API.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function rest_force_process_queue($request) {
        // Get queue instance
        $queue = $this->get_queue_instance();
        
        // Process queue
        $processed = $queue->force_process_queue();
        
        return new WP_REST_Response(array(
            'message' => sprintf(__('Successfully processed %d tasks.', 'filebird-dropbox-sync'), $processed),
            'processed' => $processed
        ), 200);
    }

    /**
     * Retry failed tasks via REST API.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function rest_retry_failed_tasks($request) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'fds_sync_queue';
        
        // Update all failed tasks to pending and reset attempts
        $updated = $wpdb->query(
            "UPDATE $queue_table 
            SET status = 'pending', attempts = 0, error_message = '', updated_at = NOW() 
            WHERE status = 'failed'"
        );
        
        // Log the action
        $this->logger->info("Reset {$updated} failed tasks to pending status");
        
        return new WP_REST_Response(array(
            'message' => sprintf(__('Reset %d failed tasks to pending status.', 'filebird-dropbox-sync'), $updated),
            'updated' => $updated
        ), 200);
    }

    /**
     * Get logs via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_get_logs() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
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
        
        // Check if the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // Try to create the table
            $this->logger->error("Logs table does not exist, attempting to create it", [
                'table_name' => $table_name
            ]);
            
            $this->db->create_tables_if_needed();
            
            // Check again
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            
            if (!$table_exists) {
                wp_send_json_error([
                    'message' => __('Logs table does not exist and could not be created.', 'filebird-dropbox-sync')
                ]);
                return;
            }
        }
        
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
                // Handle context data - ensure it's properly formatted JSON
                $context = $log->context;
                if (!empty($context)) {
                    // If it's already JSON, just pass it through
                    if (is_string($context) && $this->is_json($context)) {
                        // Already JSON - no change needed
                    } else if (is_string($context)) {
                        // Try to unserialize if it's serialized PHP
                        $unserialized = @unserialize($context);
                        if ($unserialized !== false) {
                            // Successfully unserialized, encode as JSON
                            $context = json_encode($unserialized, JSON_PRETTY_PRINT);
                        } else {
                            // Not serialized PHP, encode as simple string
                            $context = json_encode(['message' => $context], JSON_PRETTY_PRINT);
                        }
                    } else {
                        // Not a string (shouldn't happen), encode as JSON
                        $context = json_encode($context, JSON_PRETTY_PRINT);
                    }
                }
                
                $formatted_logs[] = array(
                    'id' => $log->id,
                    'level' => $log->level,
                    'message' => $log->message,
                    'context' => $context,
                    'created_at' => $log->created_at,
                );
            }
        } else {
            // Add a log entry to help with debugging
            $this->logger->error("Failed to retrieve logs", [
                'level' => $level,
                'page' => $page,
                'per_page' => $per_page,
                'logs_result' => var_export($logs, true)
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
     * Helper method to check if a string is valid JSON
     *
     * @param string $string The string to check
     * @return bool True if valid JSON, false otherwise
     */
    private function is_json($string) {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Clear logs via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_clear_logs() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Clear logs
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            // Add an initial log entry
            $this->logger->info('Logs cleared by administrator');
            
            wp_send_json_success(['message' => __('Logs cleared successfully.', 'filebird-dropbox-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to clear logs.', 'filebird-dropbox-sync')]);
        }
    }

    /**
     * Disconnect from Dropbox via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_oauth_disconnect() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
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
        
        wp_send_json_success(['message' => __('Successfully disconnected from Dropbox.', 'filebird-dropbox-sync')]);
    }

    /**
     * Get synchronization statistics via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_get_sync_stats() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        try {
            global $wpdb;
            
            // Check if tables exist
            $file_table = $wpdb->prefix . 'fds_file_mapping';
            $queue_table = $wpdb->prefix . 'fds_sync_queue';
            
            $file_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$file_table'") === $file_table;
            $queue_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
            
            if (!$file_table_exists || !$queue_table_exists) {
                // Try to create tables
                $db = new FDS_DB();
                $created = $db->create_tables_if_needed();
                
                if (!$created) {
                    wp_send_json_error(['message' => __('Database tables missing. Please deactivate and reactivate the plugin.', 'filebird-dropbox-sync')]);
                    return;
                }
            }
            
            // Original stats gathering code with null coalescing to prevent errors
            $total_files = $wpdb->get_var("SELECT COUNT(*) FROM $file_table") ?: 0;
            $synced_files = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $file_table WHERE last_synced > DATE_SUB(NOW(), INTERVAL %d HOUR)",
                    24 // Consider files synced in last 24 hours
                )
            ) ?: 0;
            
            // Rest of the stats retrieval code...
            $pending_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'pending'") ?: 0;
            $processing_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'processing'") ?: 0;
            $failed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'") ?: 0;
            $completed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'") ?: 0;
            
            // Check if there's a queue lock (processing in progress)
            $is_processing = get_transient('fds_queue_lock') ? true : false;
            
            wp_send_json_success([
                'total_files' => intval($total_files),
                'synced_files' => intval($synced_files),
                'pending_tasks' => intval($pending_tasks),
                'processing_tasks' => intval($processing_tasks),
                'failed_tasks' => intval($failed_tasks),
                'completed_tasks' => intval($completed_tasks),
                'is_processing' => $is_processing,
                'last_updated' => current_time('mysql')
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Error retrieving sync statistics:', 'filebird-dropbox-sync') . ' ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Force processing of sync queue via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_force_process_queue() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Get queue instance
        $queue = $this->get_queue_instance();
        
        // Process queue
        $processed = $queue->force_process_queue();
        
        // Log the action
        $this->logger->info("Manually processed {$processed} tasks in queue");
        
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
        if (!current_user_can('manage_options')) {
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
        
        // Log the action
        $this->logger->info("Reset {$updated} failed tasks to pending status");
        
        wp_send_json_success([
            'message' => sprintf(__('Reset %d failed tasks to pending status.', 'filebird-dropbox-sync'), $updated),
            'updated' => $updated
        ]);
    }

    /**
     * Dismiss welcome notice via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_dismiss_welcome_notice() {
        check_ajax_referer('fds-dismiss-welcome', 'nonce');
        update_option('fds_welcome_notice_dismissed', true);
        wp_send_json_success();
    }

    /**
     * Initiate manual sync via AJAX with immediate processing.
     *
     * @since    1.0.0
     */
    public function ajax_manual_sync() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            wp_send_json_error(['message' => __('Synchronization is not enabled. Please enable it in settings.', 'filebird-dropbox-sync')]);
            return;
        }
        
        // Check Dropbox connection
        $settings = new FDS_Settings();
        $dropbox_api = new FDS_Dropbox_API($settings);
        
        if (!$dropbox_api->has_valid_token()) {
            wp_send_json_error(['message' => __('No valid Dropbox connection. Please reconnect to Dropbox.', 'filebird-dropbox-sync')]);
            return;
        }
        
        // Clear any existing queue lock to prevent deadlocks
        delete_transient('fds_queue_lock');
        delete_option('fds_queue_lock_time');
        
        // Get queue instance
        $queue = $this->get_queue_instance();
        
        // Start full sync
        $result = $queue->start_full_sync();
        
        if (!$result) {
            wp_send_json_error(['message' => __('Failed to start synchronization. Please check logs.', 'filebird-dropbox-sync')]);
            return;
        }
        
        // Force immediate processing of the first batch
        // This ensures the sync actually starts right away instead of waiting for WP-Cron
        $processed = $queue->force_process_queue();
        
        // Log action
        $this->logger->info("Manual sync initiated by administrator", [
            'processed_tasks' => $processed
        ]);
        
        // Make sure next runs are scheduled
        if (class_exists('ActionScheduler') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action('fds_process_queue')) {
                as_schedule_single_action(time() + 30, 'fds_process_queue');
                $this->logger->info("Scheduled next queue processing via Action Scheduler");
            }
        } else {
            if (!wp_next_scheduled('fds_process_queue')) {
                wp_schedule_single_event(time() + 30, 'fds_process_queue');
                $this->logger->info("Scheduled next queue processing via WP-Cron");
            }
        }
        
        wp_send_json_success([
            'message' => __('Synchronization started. This process will continue in the background.', 'filebird-dropbox-sync'),
            'processed' => $processed
        ]);
    }

    /**
     * Check sync status via AJAX.
     *
     * @since    1.0.0
     */
    public function ajax_check_sync_status() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'fds_sync_queue';
        
        // Get counts
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table") ?: 0;
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'pending'") ?: 0;
        $processing = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'processing'") ?: 0;
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'") ?: 0;
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'") ?: 0;
        
        // Check if there's a lock
        $is_processing = get_transient('fds_queue_lock') ? true : false;
        
        wp_send_json_success([
            'total' => intval($total),
            'pending' => intval($pending),
            'processing' => intval($processing),
            'completed' => intval($completed),
            'failed' => intval($failed),
            'is_processing' => $is_processing
        ]);
    }

    /**
     * Get queue instance, creating it if necessary.
     * 
     * @since    1.0.0
     * @return   FDS_Queue    The queue instance.
     */
    private function get_queue_instance() {
        if ($this->queue === null) {
            // Get dependencies from service locator or create them
            $settings = new FDS_Settings();
            $dropbox_api = new FDS_Dropbox_API($settings);
            
            $folder_sync = new FDS_Folder_Sync($dropbox_api, $this->db, $this->logger);
            $file_sync = new FDS_File_Sync($dropbox_api, $this->db, $this->logger);
            
            $this->queue = new FDS_Queue($folder_sync, $file_sync, $this->logger);
        }
        
        return $this->queue;
    }
}