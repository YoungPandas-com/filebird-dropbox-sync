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
     * Process items in the queue with improved error handling and logging.
     *
     * @since    1.0.0
     */
    public function process_queued_items() {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            $this->logger->debug("Queue processing skipped - sync is disabled");
            return;
        }
        
        // Get lock with timeout value - skip checking for locks during development/debugging
        if (!$this->get_lock_with_timeout()) {
            $this->logger->debug("Could not acquire queue lock, another process might be running");
            return;
        }
        
        try {
            // Log processing start with system info
            $this->logger->info("Starting queue processing", [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'php_version' => phpversion(),
                'time' => current_time('mysql')
            ]);
            
            // Check if the Dropbox API has a valid token
            $dropbox_api = null;
            if (class_exists('FDS_Dropbox_API') && class_exists('FDS_Settings')) {
                $settings = new FDS_Settings();
                $dropbox_api = new FDS_Dropbox_API($settings, $this->logger);
                
                if (!$dropbox_api->has_valid_token()) {
                    $this->logger->error("Queue processing stopped - no valid Dropbox token");
                    $this->release_lock();
                    return;
                }
            } else {
                $this->logger->error("Queue processing skipped - required classes not found");
                $this->release_lock();
                return;
            }
            
            // Verify that all required tables exist
            if (!$this->verify_database_tables()) {
                $this->logger->error("Queue processing stopped - database tables missing");
                $this->release_lock();
                return;
            }
            
            // Get the DB instance
            $db = new FDS_DB();
            
            // Get batch size
            $batch_size = get_option('fds_queue_batch_size', 10);
            
            // Process pending items
            $items = $db->get_pending_tasks($batch_size);
            
            if (empty($items)) {
                $this->logger->debug("No pending tasks to process");
                $this->release_lock();
                
                // Make sure next process is scheduled
                $this->schedule_next_run();
                return;
            }
            
            $this->logger->info("Processing queue batch", [
                'batch_size' => count($items)
            ]);
            
            $success_count = 0;
            $failure_count = 0;
            
            foreach ($items as $item) {
                // Log item details for debugging
                $this->logger->debug("Processing queue item", [
                    'id' => $item->id,
                    'action' => $item->action,
                    'item_type' => $item->item_type,
                    'direction' => $item->direction,
                    'attempts' => $item->attempts
                ]);
                
                // Mark as processing
                $db->update_task_status($item->id, 'processing');
                
                try {
                    // Unserialize data with error checking
                    $data = $item->data;
                    if (!empty($data)) {
                        try {
                            $data = maybe_unserialize($data);
                            if (is_string($data) && !empty($data)) {
                                // Double check if it's still serialized (sometimes maybe_unserialize fails)
                                $data2 = @unserialize($data);
                                if ($data2 !== false) {
                                    $data = $data2;
                                }
                            }
                        } catch (Exception $e) {
                            $this->logger->error("Failed to unserialize data for item", [
                                'item_id' => $item->id,
                                'data' => $item->data,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    // Re-assign the data
                    $item->data = $data;
                    
                    // Process the item
                    $success = $this->process_item($item);
                    
                    // Update status
                    if ($success) {
                        $db->update_task_status($item->id, 'completed');
                        $success_count++;
                        $this->logger->info("Queue item processed successfully", [
                            'item_id' => $item->id,
                            'action' => $item->action,
                            'item_type' => $item->item_type
                        ]);
                    } else {
                        // If max retries reached, mark as failed
                        $max_retries = get_option('fds_max_retries', 3);
                        if ($item->attempts >= $max_retries) {
                            $db->update_task_status($item->id, 'failed', 'Max retry attempts reached');
                            $failure_count++;
                            $this->logger->error("Queue item failed after max retries", [
                                'item_id' => $item->id,
                                'attempts' => $item->attempts,
                                'max_retries' => $max_retries
                            ]);
                        } else {
                            $db->update_task_status($item->id, 'pending', 'Will retry later');
                            $this->logger->warning("Queue item processing failed, will retry", [
                                'item_id' => $item->id,
                                'attempts' => $item->attempts + 1
                            ]);
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
            
            // If there are more items to process, schedule another run
            if ($success_count > 0 || $failure_count > 0) {
                // Check for more pending items
                $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->prefix" . "fds_sync_queue WHERE status = 'pending'");
                
                if ($pending_count > 0) {
                    // Schedule more processing
                    $this->schedule_next_run();
                    $this->logger->debug("Scheduled next run - $pending_count pending items remain");
                }
            }
            
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
            // Handle system tasks first (like full sync)
            if ($item->item_type === 'system') {
                switch ($item->action) {
                    case 'full_sync':
                        return $this->process_full_sync_task($item);
                    default:
                        $this->logger->error("Unknown system action", array(
                            'action' => $item->action,
                            'item_id' => $item->item_id
                        ));
                        return false;
                }
            }
            
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
     * Process a full sync task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    protected function process_full_sync_task($task) {
        try {
            $this->logger->info("Starting full synchronization process", [
                'task_id' => $task->id,
                'started_at' => isset($task->data['started_at']) ? $task->data['started_at'] : current_time('mysql')
            ]);
            
            // Get all FileBird folders
            if (!class_exists('FileBird\\Model\\Folder')) {
                throw new Exception("FileBird plugin not detected");
            }
            
            // Get all folders from FileBird
            $filebird_folders = \FileBird\Model\Folder::getFolders();
            
            if (empty($filebird_folders)) {
                $this->logger->info("No FileBird folders found to sync");
                return true; // No folders to sync is still a successful sync
            }
            
            $this->logger->info("Found FileBird folders to sync", [
                'folder_count' => count($filebird_folders)
            ]);
            
            // Process each folder
            $db = new FDS_DB();
            $root_folder = get_option('fds_root_dropbox_folder', '/Website');
            
            // Add root folder task first
            $db->add_to_sync_queue(
                'create',
                'folder',
                '0', // Root folder ID
                'wordpress_to_dropbox',
                array(
                    'folder_id' => 0,
                    'folder_name' => basename($root_folder),
                    'folder_path' => $root_folder,
                ),
                2 // High priority
            );
            
            // Queue folder sync tasks (creates or updates for folders)
            foreach ($filebird_folders as $folder) {
                $folder_id = $folder->id;
                $folder_path = $this->folder_sync->get_dropbox_path_for_filebird_folder($folder_id);
                
                if (!$folder_path) {
                    $this->logger->warning("Failed to determine Dropbox path for folder", [
                        'folder_id' => $folder_id,
                        'folder_name' => $folder->name
                    ]);
                    continue;
                }
                
                // Add folder task
                $db->add_to_sync_queue(
                    'create',
                    'folder',
                    (string) $folder_id,
                    'wordpress_to_dropbox',
                    array(
                        'folder_id' => $folder_id,
                        'folder_name' => $folder->name,
                        'folder_path' => $folder_path,
                    ),
                    3 // Moderate priority
                );
                
                // Get files in the folder
                if (class_exists('FileBird\\Model\\Folder')) {
                    $args = array(
                        'post_type' => 'attachment',
                        'posts_per_page' => -1,
                        'post_status' => 'inherit',
                        'fields' => 'ids',
                        'meta_query' => array(
                            array(
                                'key' => '_wp_attached_file',
                                'compare' => 'EXISTS',
                            ),
                        )
                    );
                    
                    // Get attachment IDs in this folder
                    $attachment_ids = \FileBird\Model\Folder::getAttachmentIdsByFolderId($folder_id);
                    
                    if (!empty($attachment_ids)) {
                        $this->logger->info("Found files to sync in folder", [
                            'folder_id' => $folder_id,
                            'file_count' => count($attachment_ids)
                        ]);
                        
                        // Queue file sync tasks
                        foreach ($attachment_ids as $attachment_id) {
                            // Add file task
                            $file_path = get_attached_file($attachment_id);
                            if (!$file_path || !file_exists($file_path)) {
                                continue;
                            }
                            
                            $dropbox_path = $this->file_sync->get_dropbox_path_for_attachment($attachment_id, $folder_id);
                            
                            if (!$dropbox_path) {
                                continue;
                            }
                            
                            $db->add_to_sync_queue(
                                'create',
                                'file',
                                (string) $attachment_id,
                                'wordpress_to_dropbox',
                                array(
                                    'attachment_id' => $attachment_id,
                                    'local_path' => $file_path,
                                    'dropbox_path' => $dropbox_path,
                                    'folder_id' => $folder_id,
                                ),
                                4 // Lower priority than folders
                            );
                        }
                    }
                }
            }
            
            // Force immediate processing of next batch
            $this->schedule_next_run();
            
            $this->logger->info("Full sync task processed successfully, queued all folders and files for sync");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Full sync task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id
            ]);
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
     * @return   boolean    True if sync was started successfully, false otherwise.
     */
    public function start_full_sync() {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            $this->logger->warning("Cannot start full sync - sync is not enabled");
            return false;
        }
        
        // Check Dropbox connection
        $dropbox_api = new FDS_Dropbox_API(new FDS_Settings());
        if (!$dropbox_api->has_valid_token()) {
            $this->logger->error("Cannot start full sync - no valid Dropbox token");
            return false;
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
        
        return true;
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

    /**
     * Get a lock with more reliable timeout handling.
     *
     * @return boolean True if lock acquired, false otherwise.
     */
    protected function get_lock_with_timeout() {
        $lock = get_transient('fds_queue_lock');
        
        if ($lock) {
            // Check if the lock is stale (older than the lock time)
            $lock_time = intval(get_option('fds_queue_lock_time', 0));
            
            if ($lock_time > 0 && time() - $lock_time > $this->lock_time) {
                // Lock is stale, force release it
                $this->logger->warning("Detected stale queue lock, forcing release", [
                    'lock_time' => date('Y-m-d H:i:s', $lock_time),
                    'stale_for' => human_time_diff($lock_time + $this->lock_time, time())
                ]);
                
                delete_transient('fds_queue_lock');
                delete_option('fds_queue_lock_time');
                
                // Sleep briefly to allow other processes to finish
                sleep(2);
                
                // Try to acquire the lock again
                return $this->get_lock_with_timeout();
            }
            
            return false;
        }
        
        // Set the lock and record the time
        set_transient('fds_queue_lock', '1', $this->lock_time);
        update_option('fds_queue_lock_time', time());
        
        return true;
    }

    /**
     * Schedule the next queue processing run.
     */
    protected function schedule_next_run() {
        // If Action Scheduler is available, use it
        if (class_exists('ActionScheduler') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action('fds_process_queue')) {
                as_schedule_single_action(time() + 30, 'fds_process_queue');
            }
        } else {
            // Otherwise use WP-Cron
            if (!wp_next_scheduled('fds_process_queue')) {
                wp_schedule_single_event(time() + 30, 'fds_process_queue');
            }
        }
    }

    /**
     * Verify that all required database tables exist.
     * 
     * @return boolean True if all tables exist, false otherwise.
     */
    protected function verify_database_tables() {
        global $wpdb;
        
        $required_tables = [
            $wpdb->prefix . 'fds_folder_mapping',
            $wpdb->prefix . 'fds_file_mapping',
            $wpdb->prefix . 'fds_sync_queue',
            $wpdb->prefix . 'fds_logs'
        ];
        
        foreach ($required_tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return false;
            }
        }
        
        return true;
    }
}