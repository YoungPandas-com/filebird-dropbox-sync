<?php
/**
 * Handles the synchronization queue.
 *
 * This class provides methods to process the synchronization queue asynchronously.
 *
 * @since      1.0.0
 */
class FDS_Queue {

    /**
     * The folder sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Folder_Sync    $folder_sync    The folder sync instance.
     */
    protected $folder_sync;

    /**
     * The file sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_File_Sync    $file_sync    The file sync instance.
     */
    protected $file_sync;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    The logger instance.
     */
    protected $logger;

    /**
     * The lock time for queue processing.
     *
     * @since    1.0.0
     * @access   protected
     * @var      int    $lock_time    The lock time in seconds.
     */
    protected $lock_time = 300; // 5 minutes

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    FDS_Folder_Sync    $folder_sync    The folder sync instance.
     * @param    FDS_File_Sync    $file_sync        The file sync instance.
     * @param    FDS_Logger    $logger              The logger instance.
     */
    public function __construct($folder_sync, $file_sync, $logger) {
        $this->folder_sync = $folder_sync;
        $this->file_sync = $file_sync;
        $this->logger = $logger;
    }

    /**
     * Process items in the queue.
     *
     * @since    1.0.0
     */
    public function process_queued_items() {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        // Get lock
        if (!$this->get_lock()) {
            $this->logger->debug("Could not acquire queue lock, another process is running");
            return;
        }
        
        try {
            // Get the DB instance
            $db = new FDS_DB();
            
            // Get batch size
            $batch_size = get_option('fds_queue_batch_size', 10);
            
            // Process pending items
            $items = $db->get_pending_tasks($batch_size);
            
            if (empty($items)) {
                $this->logger->debug("No pending tasks to process");
                return;
            }
            
            $this->logger->info("Processing queue batch", [
                'batch_size' => count($items)
            ]);
            
            $success_count = 0;
            $failure_count = 0;
            
            foreach ($items as $item) {
                // Mark as processing
                $db->update_task_status($item->id, 'processing');
                
                try {
                    // Process the item
                    $success = $this->process_item($item);
                    
                    // Update status
                    if ($success) {
                        $db->update_task_status($item->id, 'completed');
                        $success_count++;
                    } else {
                        // If max retries reached, mark as failed
                        $max_retries = get_option('fds_max_retries', 3);
                        if ($item->attempts >= $max_retries) {
                            $db->update_task_status($item->id, 'failed', 'Max retry attempts reached');
                            $failure_count++;
                        } else {
                            $db->update_task_status($item->id, 'pending', 'Will retry later');
                        }
                    }
                } catch (Exception $e) {
                    $error_message = "Exception while processing item: " . $e->getMessage();
                    $db->update_task_status($item->id, 'failed', $error_message);
                    $this->logger->error($error_message, [
                        'item_id' => $item->id,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $failure_count++;
                }
            }
            
            $this->logger->info("Queue batch processing completed", [
                'success_count' => $success_count,
                'failure_count' => $failure_count,
                'total' => count($items)
            ]);
            
            // Cleanup completed tasks older than 7 days
            $db->cleanup_completed_tasks(7);
        } catch (Exception $e) {
            $this->logger->error("Fatal error in queue processing", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            // ALWAYS release lock to prevent queue processing deadlock
            $this->release_lock();
        }
    }

    /**
     * Force process queue items, ignoring locks.
     *
     * @since    1.0.0
     * @return   int    The number of successfully processed items.
     */
    public function force_process_queue() {
        // Ignore lock for force processing
        delete_transient('fds_queue_lock');
        
        // Process a larger batch
        $batch_size = 50; // Process more items at once
        
        // Get the DB instance
        $db = new FDS_DB();
        
        // Process pending items
        $items = $db->get_pending_tasks($batch_size);
        
        if (empty($items)) {
            $this->logger->info("No pending tasks to process during force processing");
            return 0;
        }
        
        $this->logger->info("Force processing queue batch", [
            'batch_size' => count($items)
        ]);
        
        $success_count = 0;
        $failure_count = 0;
        
        foreach ($items as $item) {
            // Mark as processing
            $db->update_task_status($item->id, 'processing');
            
            try {
                // Process the item
                $success = $this->process_item($item);
                
                // Update status
                if ($success) {
                    $db->update_task_status($item->id, 'completed');
                    $success_count++;
                } else {
                    // If max retries reached, mark as failed
                    $max_retries = get_option('fds_max_retries', 5);
                    if ($item->attempts >= $max_retries) {
                        $db->update_task_status($item->id, 'failed', 'Max retry attempts reached');
                        $failure_count++;
                    } else {
                        $db->update_task_status($item->id, 'pending', 'Will retry later');
                    }
                }
            } catch (Exception $e) {
                $error_message = "Exception while processing item: " . $e->getMessage();
                $db->update_task_status($item->id, 'failed', $error_message);
                $this->logger->error($error_message, [
                    'item_id' => $item->id,
                    'exception' => $e->getMessage()
                ]);
                $failure_count++;
            }
        }
        
        $this->logger->info("Force queue processing completed", [
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'total' => count($items)
        ]);
        
        return $success_count;
    }

    /**
     * Process an individual queue item.
     *
     * @since    1.0.0
     * @param    object    $item    The queue item to process.
     * @return   boolean            True on success, false on failure.
     */
    protected function process_item($item) {
        $this->logger->debug("Processing queue item", array(
            'id' => $item->id,
            'action' => $item->action,
            'item_type' => $item->item_type,
            'direction' => $item->direction
        ));
        
        try {
            // Determine the action to take
            if ($item->direction === 'wordpress_to_dropbox') {
                if ($item->item_type === 'folder') {
                    switch ($item->action) {
                        case 'create':
                            return $this->folder_sync->process_folder_create_task($item);
                        case 'rename':
                            return $this->folder_sync->process_folder_rename_task($item);
                        case 'delete':
                            return $this->folder_sync->process_folder_delete_task($item);
                        case 'move':
                            return $this->folder_sync->process_folder_move_task($item);
                        default:
                            $this->logger->error("Unknown folder action", array(
                                'action' => $item->action,
                                'item_id' => $item->item_id
                            ));
                            return false;
                    }
                } elseif ($item->item_type === 'file') {
                    switch ($item->action) {
                        case 'create':
                            return $this->file_sync->process_file_create_task($item);
                        case 'update':
                            return $this->file_sync->process_file_update_task($item);
                        case 'delete':
                            return $this->file_sync->process_file_delete_task($item);
                        case 'move':
                            return $this->file_sync->process_file_move_task($item);
                        default:
                            $this->logger->error("Unknown file action", array(
                                'action' => $item->action,
                                'item_id' => $item->item_id
                            ));
                            return false;
                    }
                }
            } elseif ($item->direction === 'dropbox_to_wordpress') {
                // Handled by webhook and file/folder specific methods
                $this->logger->error("Dropbox to WordPress sync should be handled by webhook", array(
                    'item' => $item
                ));
                return false;
            }
            
            $this->logger->error("Unknown sync direction", array(
                'direction' => $item->direction,
                'item_id' => $item->item_id
            ));
            return false;
        } catch (Exception $e) {
            $this->logger->error("Exception processing queue item", array(
                'exception' => $e->getMessage(),
                'item_id' => $item->id
            ));
            return false;
        }
    }

    /**
     * Handle AJAX request for manual sync.
     *
     * @since    1.0.0
     */
    public function ajax_manual_sync() {
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'filebird-dropbox-sync')));
        }
        
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            wp_send_json_error(array('message' => __('Synchronization is not enabled.', 'filebird-dropbox-sync')));
        }
        
        // Start the sync
        $this->start_full_sync();
        
        wp_send_json_success(array('message' => __('Synchronization started. This process will continue in the background.', 'filebird-dropbox-sync')));
    }

    /**
     * Handle AJAX request to check sync status.
     *
     * @since    1.0.0
     */
    public function ajax_check_sync_status() {
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_fds_settings')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'filebird-dropbox-sync')));
        }
        
        // Get the DB instance
        $db = new FDS_DB();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        
        // Get counts
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
        $processing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'processing'");
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");
        
        // Check if there's a lock
        $lock = get_transient('fds_queue_lock');
        
        wp_send_json_success(array(
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'is_processing' => !empty($lock),
        ));
    }

        /**
     * Process queue items for a specific worker.
     *
     * @since    1.0.0
     * @param    int     $worker_id    The worker ID.
     * @param    int     $batch_size   Number of items to process.
     */
    public function process_worker_queue($worker_id, $batch_size = 5) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        // Get worker-specific lock
        if (!$this->get_worker_lock($worker_id)) {
            return;
        }
        
        // Get the DB instance
        $db = new FDS_DB();
        
        // Process pending items for this worker
        $items = $this->get_worker_items($worker_id, $batch_size);
        
        if (empty($items)) {
            $this->release_worker_lock($worker_id);
            return;
        }
        
        foreach ($items as $item) {
            // Mark as processing
            $db->update_task_status($item->id, 'processing');
            
            // Process the item
            $success = $this->process_item($item);
            
            // Update status
            if ($success) {
                $db->update_task_status($item->id, 'completed');
            } else {
                // If max retries reached, mark as failed
                $max_retries = get_option('fds_max_retries', 5);
                if ($item->attempts >= $max_retries) {
                    $db->update_task_status($item->id, 'failed', 'Max retry attempts reached');
                } else {
                    $db->update_task_status($item->id, 'pending', 'Will retry later');
                }
            }
        }
        
        // Release lock
        $this->release_worker_lock($worker_id);
    }

    /**
     * Get worker-specific lock.
     *
     * @since    1.0.0
     * @param    int     $worker_id    The worker ID.
     * @return   boolean               True if lock acquired.
     */
    protected function get_worker_lock($worker_id) {
        $lock = get_transient("fds_queue_lock_worker_{$worker_id}");
        
        if ($lock) {
            return false;
        }
        
        set_transient("fds_queue_lock_worker_{$worker_id}", '1', 300);
        
        return true;
    }

    /**
     * Release worker-specific lock.
     *
     * @since    1.0.0
     * @param    int     $worker_id    The worker ID.
     */
    protected function release_worker_lock($worker_id) {
        delete_transient("fds_queue_lock_worker_{$worker_id}");
    }

    /**
     * Get items for a specific worker.
     *
     * @since    1.0.0
     * @param    int     $worker_id     The worker ID.
     * @param    int     $batch_size    Number of items to fetch.
     * @return   array                  Array of queue items.
     */
    protected function get_worker_items($worker_id, $batch_size) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        $max_retries = get_option('fds_max_retries', 5);
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE status = 'pending' 
                AND attempts < %d 
                AND (worker_id = 0 OR worker_id = %d)
                ORDER BY priority ASC, created_at ASC 
                LIMIT %d",
                $max_retries,
                $worker_id,
                $batch_size
            )
        );
    }

    /**
     * Start a full sync between WordPress and Dropbox.
     *
     * @since    1.0.0
     */
    public function start_full_sync() {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        // Queue the sync task
        $db = new FDS_DB();
        
        $db->add_to_sync_queue(
            'full_sync',
            'system',
            'full_sync',
            'wordpress_to_dropbox',
            array(
                'started_at' => current_time('mysql'),
            ),
            1 // Highest priority
        );
        
        $this->logger->info("Full sync queued");
        
        // Trigger an immediate cron event to start processing
        wp_schedule_single_event(time(), 'fds_process_queue');
    }

    /**
     * Get a lock for queue processing.
     *
     * @since    1.0.0
     * @return   boolean    True if lock acquired, false otherwise.
     */
    protected function get_lock() {
        $lock = get_transient('fds_queue_lock');
        
        if ($lock) {
            return false;
        }
        
        set_transient('fds_queue_lock', '1', $this->lock_time);
        
        return true;
    }

    /**
     * Release the lock for queue processing.
     *
     * @since    1.0.0
     */
    protected function release_lock() {
        delete_transient('fds_queue_lock');
    }
}