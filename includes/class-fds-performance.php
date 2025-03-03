<?php
/**
 * Handles performance optimizations for large-scale synchronization.
 *
 * This class provides methods for optimizing performance with 100,000+ files.
 *
 * @since      1.0.0
 */
class FDS_Performance {

    /**
     * The database instance.
     * 
     * @var FDS_DB
     */
    protected $db;

    /**
     * The logger instance.
     * 
     * @var FDS_Logger
     */
    protected $logger;

    /**
     * Memory cache for frequently accessed data.
     * 
     * @var array
     */
    protected $cache = [];

    /**
     * Cache expiration in seconds.
     * 
     * @var int
     */
    protected $cache_expiration = 300; // 5 minutes

    /**
     * Initialize the class.
     *
     * @param FDS_DB $db The database instance.
     * @param FDS_Logger $logger The logger instance.
     */
    public function __construct($db, $logger) {
        $this->db = $db;
        $this->logger = $logger;
        
        // Register optimization hooks
        $this->register_hooks();
    }

    /**
     * Register optimization hooks.
     */
    protected function register_hooks() {
        // Use Action Scheduler instead of WP-Cron for reliable background processing
        add_action('plugins_loaded', [$this, 'register_action_scheduler']);
        
        // Database optimization hooks
        add_action('fds_weekly_maintenance', [$this, 'optimize_database_tables']);
        
        // Register additional workers for parallel processing
        add_action('fds_process_queue_worker', [$this, 'process_queue_worker']);
        
        // Schedule maintenance tasks
        if (!wp_next_scheduled('fds_weekly_maintenance')) {
            wp_schedule_event(time(), 'weekly', 'fds_weekly_maintenance');
        }
    }
    
    /**
     * Register with Action Scheduler for better background processing.
     */
    public function register_action_scheduler() {
        if (class_exists('ActionScheduler')) {
            // Replace standard WP-Cron with Action Scheduler for queue processing
            remove_action('fds_process_queue', 'process_queued_items');
            
            // Schedule multiple parallel workers for better throughput
            for ($i = 1; $i <= 5; $i++) {
                as_schedule_recurring_action(time(), 60, 'fds_process_queue_worker', ['worker_id' => $i], 'filebird-dropbox-sync');
            }
        } else {
            $this->logger->warning('Action Scheduler not available. Using standard WP-Cron.');
        }
    }
    
    /**
     * Worker process for handling queue items in parallel.
     * 
     * @param int $worker_id The worker identifier.
     */
    public function process_queue_worker($worker_id) {
        // Get the queue instance
        $queue = new FDS_Queue(
            new FDS_Folder_Sync(new FDS_Dropbox_API(new FDS_Settings()), $this->db, $this->logger),
            new FDS_File_Sync(new FDS_Dropbox_API(new FDS_Settings()), $this->db, $this->logger),
            $this->logger
        );
        
        // Process a batch of items specific to this worker
        $queue->process_worker_queue($worker_id, 5);
    }
    
    /**
     * Optimize database tables for better performance.
     */
    public function optimize_database_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'fds_folder_mapping',
            $wpdb->prefix . 'fds_file_mapping',
            $wpdb->prefix . 'fds_sync_queue',
            $wpdb->prefix . 'fds_logs'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
        
        $this->logger->info('Optimized database tables for better performance');
    }
    
    /**
     * Get cached data or fetch from source.
     * 
     * @param string $key Cache key.
     * @param callable $callback Function to call if cache misses.
     * @return mixed Cached or fresh data.
     */
    public function get_cached_data($key, $callback) {
        if (isset($this->cache[$key]) && time() < $this->cache[$key]['expires']) {
            return $this->cache[$key]['data'];
        }
        
        // Cache miss, call the callback to get fresh data
        $data = call_user_func($callback);
        
        // Store in cache
        $this->cache[$key] = [
            'data' => $data,
            'expires' => time() + $this->cache_expiration
        ];
        
        return $data;
    }
    
    /**
     * Chunked file upload for better handling of large files.
     * 
     * @param FDS_Dropbox_API $dropbox_api Dropbox API instance.
     * @param string $local_path Local file path.
     * @param string $dropbox_path Dropbox path.
     * @param int $chunk_size Chunk size in bytes (default 4MB).
     * @return array|bool Dropbox file metadata or false on failure.
     */
    public function chunked_upload($dropbox_api, $local_path, $dropbox_path, $chunk_size = 4194304) {
        if (!file_exists($local_path)) {
            return false;
        }
        
        $file_size = filesize($local_path);
        
        // For small files, use direct upload
        if ($file_size <= $chunk_size) {
            return $dropbox_api->upload_file($local_path, $dropbox_path);
        }
        
        try {
            $file_handle = fopen($local_path, 'rb');
            if (!$file_handle) {
                throw new Exception("Cannot open file: $local_path");
            }
            
            $session_id = null;
            $offset = 0;
            $retry_count = 0;
            $max_retries = 3;
            
            // Start upload session
            while ($offset < $file_size) {
                $chunk = fread($file_handle, $chunk_size);
                
                if ($chunk === false) {
                    throw new Exception("Failed to read chunk from file");
                }
                
                // Implement retry logic with exponential backoff
                $retry = true;
                $retry_count = 0;
                
                while ($retry && $retry_count < $max_retries) {
                    try {
                        if ($offset === 0) {
                            // Start new upload session
                            $session_id = $dropbox_api->start_upload_session($chunk);
                        } else {
                            // Append to existing upload session
                            $dropbox_api->append_to_upload_session($session_id, $chunk, $offset);
                        }
                        $retry = false;
                    } catch (Exception $e) {
                        $retry_count++;
                        if ($retry_count >= $max_retries) {
                            throw $e;
                        }
                        
                        // Exponential backoff
                        sleep(pow(2, $retry_count));
                    }
                }
                
                $offset += strlen($chunk);
            }
            
            // Finish upload session
            $result = $dropbox_api->finish_upload_session($session_id, $dropbox_path, $file_size);
            fclose($file_handle);
            
            return $result;
        } catch (Exception $e) {
            if (isset($file_handle) && is_resource($file_handle)) {
                fclose($file_handle);
            }
            
            $this->logger->error("Chunked upload failed", [
                'exception' => $e->getMessage(),
                'local_path' => $local_path,
                'dropbox_path' => $dropbox_path
            ]);
            
            return false;
        }
    }
    
    /**
     * Enhanced chunked file upload with reliability improvements.
     * 
     * @param string $local_path Local file path.
     * @param string $dropbox_path Dropbox destination path.
     * @param int $chunk_size Optional chunk size in bytes.
     * @return array|false File metadata or false on failure.
     */
    public function enhanced_chunked_upload($local_path, $dropbox_path, $chunk_size = null) {
        if (!$chunk_size) {
            $chunk_size = get_option('fds_chunk_size', 8388608); // Default 8MB
        }
        
        if (!file_exists($local_path)) {
            $this->logger->error("File not found for chunked upload", [
                'local_path' => $local_path
            ]);
            return false;
        }
        
        $file_size = filesize($local_path);
        if ($file_size <= 0) {
            $this->logger->error("Invalid file size for upload", [
                'local_path' => $local_path,
                'size' => $file_size
            ]);
            return false;
        }
        
        $dropbox_api = new FDS_Dropbox_API(new FDS_Settings(), $this->logger);
        
        try {
            $file_handle = fopen($local_path, 'rb');
            if (!$file_handle) {
                throw new Exception("Cannot open file for reading");
            }
            
            // Track progress
            $start_time = microtime(true);
            $bytes_uploaded = 0;
            $session_id = null;
            
            // Initialize session with retry
            $max_retries = 3;
            $retry = 0;
            
            do {
                try {
                    $chunk = fread($file_handle, $chunk_size);
                    if ($chunk === false) {
                        throw new Exception("Failed to read initial chunk");
                    }
                    
                    $session_id = $dropbox_api->start_upload_session($chunk);
                    if (is_wp_error($session_id)) {
                        throw new Exception($session_id->get_error_message());
                    }
                    
                    // Track progress
                    $bytes_uploaded += strlen($chunk);
                    $success = true;
                } catch (Exception $e) {
                    $retry++;
                    if ($retry >= $max_retries) {
                        throw new Exception("Failed to start upload session after {$max_retries} attempts: " . $e->getMessage());
                    }
                    
                    $this->logger->warning("Retrying session start", [
                        'retry' => $retry,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Reset file pointer for retry
                    rewind($file_handle);
                    $bytes_uploaded = 0;
                    
                    // Exponential backoff
                    sleep(pow(2, $retry));
                    $success = false;
                }
            } while (!$success);
            
            // Upload remaining chunks
            while ($bytes_uploaded < $file_size) {
                // Calculate bytes remaining
                $bytes_remaining = $file_size - $bytes_uploaded;
                $bytes_to_read = min($bytes_remaining, $chunk_size);
                
                // Read chunk
                $chunk = fread($file_handle, $bytes_to_read);
                if ($chunk === false) {
                    throw new Exception("Failed to read chunk at offset {$bytes_uploaded}");
                }
                
                // Upload with retry
                $retry = 0;
                $success = false;
                
                while (!$success && $retry < $max_retries) {
                    try {
                        $result = $dropbox_api->append_to_upload_session($session_id, $chunk, $bytes_uploaded);
                        if (is_wp_error($result)) {
                            throw new Exception($result->get_error_message());
                        }
                        
                        $bytes_uploaded += strlen($chunk);
                        $success = true;
                        
                        // Log progress for large files
                        if ($file_size > 100 * 1024 * 1024) { // 100MB
                            $percent = round(($bytes_uploaded / $file_size) * 100);
                            $this->logger->debug("Upload progress", [
                                'percent' => $percent,
                                'bytes' => $bytes_uploaded,
                                'total' => $file_size
                            ]);
                        }
                    } catch (Exception $e) {
                        $retry++;
                        $this->logger->warning("Chunk upload failed, retrying", [
                            'retry' => $retry,
                            'offset' => $bytes_uploaded,
                            'error' => $e->getMessage()
                        ]);
                        
                        if ($retry >= $max_retries) {
                            throw new Exception("Failed to upload chunk after {$max_retries} attempts: " . $e->getMessage());
                        }
                        
                        // Exponential backoff
                        sleep(pow(2, $retry));
                    }
                }
            }
            
            // Finish upload
            $retry = 0;
            $success = false;
            
            while (!$success && $retry < $max_retries) {
                try {
                    $result = $dropbox_api->finish_upload_session($session_id, $dropbox_path, $file_size);
                    if (is_wp_error($result)) {
                        throw new Exception($result->get_error_message());
                    }
                    
                    $success = true;
                } catch (Exception $e) {
                    $retry++;
                    $this->logger->warning("Failed to finish upload, retrying", [
                        'retry' => $retry,
                        'error' => $e->getMessage()
                    ]);
                    
                    if ($retry >= $max_retries) {
                        throw new Exception("Failed to finish upload after {$max_retries} attempts: " . $e->getMessage());
                    }
                    
                    // Exponential backoff
                    sleep(pow(2, $retry));
                }
            }
            
            // Close file
            fclose($file_handle);
            
            // Calculate upload stats
            $elapsed = microtime(true) - $start_time;
            $speed_kbps = round(($file_size / 1024) / $elapsed, 2);
            
            $this->logger->info("Chunked upload completed successfully", [
                'path' => $dropbox_path,
                'size' => $file_size,
                'elapsed_seconds' => round($elapsed, 2),
                'speed_kbps' => $speed_kbps
            ]);
            
            return $result;
        } catch (Exception $e) {
            if (isset($file_handle) && is_resource($file_handle)) {
                fclose($file_handle);
            }
            
            $this->logger->error("Chunked upload failed", [
                'exception' => $e->getMessage(),
                'file' => $local_path,
                'destination' => $dropbox_path
            ]);
            
            return false;
        }
    }
    
    /**
     * Delta sync for efficiently handling initial large-scale synchronization.
     * 
     * @param FDS_Dropbox_API $dropbox_api Dropbox API instance.
     * @param string $path Dropbox path to sync.
     * @return bool True on success, false on failure.
     */
    public function delta_sync($dropbox_api, $path) {
        try {
            $delta_cursor = get_option('fds_delta_cursor_' . md5($path), '');
            $has_more = true;
            $batch_count = 0;
            $max_batches = 10; // Limit batches per run to avoid timeouts
            
            while ($has_more && $batch_count < $max_batches) {
                $result = $dropbox_api->list_folder_continue($delta_cursor);
                
                if (!$result) {
                    $this->logger->error("Failed to get delta for path", [
                        'path' => $path,
                        'cursor' => $delta_cursor
                    ]);
                    return false;
                }
                
                // Process entries in this batch
                if (!empty($result['entries'])) {
                    $this->process_delta_entries($result['entries']);
                }
                
                // Update cursor and has_more flag
                $delta_cursor = $result['cursor'];
                $has_more = $result['has_more'];
                $batch_count++;
                
                // Save cursor for future delta syncs
                update_option('fds_delta_cursor_' . md5($path), $delta_cursor);
            }
            
            // If there's more, schedule next batch
            if ($has_more) {
                if (class_exists('ActionScheduler')) {
                    as_schedule_single_action(time() + 10, 'fds_continue_delta_sync', ['path' => $path], 'filebird-dropbox-sync');
                } else {
                    wp_schedule_single_event(time() + 10, 'fds_continue_delta_sync', ['path' => $path]);
                }
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Delta sync failed", [
                'exception' => $e->getMessage(),
                'path' => $path
            ]);
            
            return false;
        }
    }
    
    /**
     * Process delta entries for efficient sync.
     * 
     * @param array $entries Delta entries from Dropbox.
     */
    protected function process_delta_entries($entries) {
        // Implementation for processing delta entries
        // This would batch them into the queue efficiently
        $folder_batch = [];
        $file_batch = [];
        
        foreach ($entries as $entry) {
            if (!isset($entry['path_lower'])) {
                continue;
            }
            
            // Sort entries into folders and files for optimal processing order
            if (isset($entry['.tag'])) {
                switch ($entry['.tag']) {
                    case 'folder':
                        $folder_batch[] = $entry;
                        break;
                    case 'file':
                        $file_batch[] = $entry;
                        break;
                    case 'deleted':
                        // Handle deletions separately (they require different processing)
                        $this->process_delta_deletion($entry);
                        break;
                }
            }
        }
        
        // Process folders first (to ensure proper hierarchy)
        if (!empty($folder_batch)) {
            $this->batch_process_delta_folders($folder_batch);
        }
        
        // Then process files
        if (!empty($file_batch)) {
            $this->batch_process_delta_files($file_batch);
        }
    }
    
    /**
     * Batch process folder entries from delta sync.
     * 
     * @param array $folders Folder entries.
     */
    protected function batch_process_delta_folders($folders) {
        // Implementation for batch processing folders
        global $wpdb;
        
        // Sort folders by path depth to ensure parents are created first
        usort($folders, function($a, $b) {
            return substr_count($a['path_lower'], '/') - substr_count($b['path_lower'], '/');
        });
        
        // Prepare batch data
        $values = [];
        $placeholders = [];
        
        foreach ($folders as $folder) {
            // Add to queue with optimized batch insertion
            $path = $folder['path_lower'];
            $data = json_encode([
                'dropbox_path' => $path,
                'folder_name' => basename($path),
                'created_at' => current_time('mysql')
            ]);
            
            $values[] = md5($path);
            $values[] = 'create';
            $values[] = 'folder';
            $values[] = 'dropbox_to_wordpress';
            $values[] = $data;
            $values[] = 5; // Priority
            $values[] = 'pending';
            $values[] = 0; // Attempts
            $values[] = current_time('mysql');
            $values[] = current_time('mysql');
            
            $placeholders[] = "(%s, %s, %s, %s, %s, %d, %s, %d, %s, %s)";
        }
        
        // Batch insert into queue if we have folders
        if (!empty($placeholders)) {
            $table_name = $wpdb->prefix . 'fds_sync_queue';
            $query = "INSERT INTO $table_name 
                      (item_id, action, item_type, direction, data, priority, status, attempts, created_at, updated_at) 
                      VALUES " . implode(', ', $placeholders);
            
            $wpdb->query($wpdb->prepare($query, $values));
        }
    }
    
    /**
     * Batch process file entries from delta sync.
     * 
     * @param array $files File entries.
     */
    protected function batch_process_delta_files($files) {
        // Implementation for batch processing files
        global $wpdb;
        
        // Prepare batch data
        $values = [];
        $placeholders = [];
        
        foreach ($files as $file) {
            // Add to queue with optimized batch insertion
            $path = $file['path_lower'];
            $data = json_encode([
                'dropbox_path' => $path,
                'dropbox_metadata' => $file,
                'created_at' => current_time('mysql')
            ]);
            
            $values[] = md5($path);
            $values[] = 'create';
            $values[] = 'file';
            $values[] = 'dropbox_to_wordpress';
            $values[] = $data;
            $values[] = 10; // Priority
            $values[] = 'pending';
            $values[] = 0; // Attempts
            $values[] = current_time('mysql');
            $values[] = current_time('mysql');
            
            $placeholders[] = "(%s, %s, %s, %s, %s, %d, %s, %d, %s, %s)";
        }
        
        // Batch insert into queue if we have files
        if (!empty($placeholders)) {
            $table_name = $wpdb->prefix . 'fds_sync_queue';
            $query = "INSERT INTO $table_name 
                      (item_id, action, item_type, direction, data, priority, status, attempts, created_at, updated_at) 
                      VALUES " . implode(', ', $placeholders);
            
            $wpdb->query($wpdb->prepare($query, $values));
        }
    }
    
    /**
     * Process deletion entry from delta sync.
     * 
     * @param array $entry Deletion entry.
     */
    protected function process_delta_deletion($entry) {
        // Add deletion task to queue
        $path = $entry['path_lower'];
        
        // Check if it's a file or folder in our system
        $file_mapping = $this->db->get_file_mapping_by_dropbox_path($path);
        if ($file_mapping) {
            $this->db->add_to_sync_queue(
                'delete',
                'file',
                (string) $file_mapping->attachment_id,
                'dropbox_to_wordpress',
                ['dropbox_path' => $path],
                10
            );
            return;
        }
        
        $folder_mapping = $this->db->get_folder_mapping_by_dropbox_path($path);
        if ($folder_mapping) {
            $this->db->add_to_sync_queue(
                'delete',
                'folder',
                (string) $folder_mapping->filebird_folder_id,
                'dropbox_to_wordpress',
                ['dropbox_path' => $path],
                5
            );
        }
    }

    /**
     * Setup parallel processing for better performance with enhanced error handling
     * 
     * @return boolean True if successfully set up Action Scheduler, false if using WP-Cron fallback
     */
    public function setup_parallel_processing() {
        // Ensure logger is available and create if not
        if (!isset($this->logger) || !is_object($this->logger)) {
            try {
                $this->logger = new FDS_Logger();
            } catch (Exception $e) {
                // Cannot create logger, create minimal implementation
                $this->logger = new stdClass();
                $this->logger->info = function($msg) { error_log('FDS Info: ' . $msg); };
                $this->logger->error = function($msg) { error_log('FDS Error: ' . $msg); };
                $this->logger->warning = function($msg) { error_log('FDS Warning: ' . $msg); };
            }
        }
        
        // Initialize success tracking
        $action_scheduler_success = false;
        
        // Check if Action Scheduler is available
        if (class_exists('ActionScheduler') && class_exists('ActionScheduler_QueueRunner')) {
            try {
                // Validate dependencies are available
                if (!function_exists('as_unschedule_all_actions') || 
                    !function_exists('as_schedule_recurring_action') || 
                    !function_exists('as_next_scheduled_action')) {
                    throw new Exception('Required Action Scheduler functions are missing');
                }
                
                // Unschedule existing actions to prevent duplicates
                as_unschedule_all_actions('fds_process_queue_worker', null, 'filebird-dropbox-sync');
                $this->logger->info("Removed any existing queue worker schedules");
                
                // Get and validate worker count from settings
                $worker_count = intval(get_option('fds_worker_count', 5));
                if ($worker_count <= 0) {
                    $worker_count = 1; // Ensure at least one worker
                    $this->logger->warning("Invalid worker count detected, defaulting to 1 worker");
                } else if ($worker_count > 10) {
                    $worker_count = 10; // Cap at 10 workers to prevent overload
                    $this->logger->warning("Excessive worker count detected, limiting to 10 workers");
                }
                
                // Schedule multiple workers for parallel processing
                $scheduled_workers = 0;
                for ($i = 1; $i <= $worker_count; $i++) {
                    try {
                        // Schedule immediately and then recurring
                        as_schedule_recurring_action(
                            time(), 
                            max(30, intval(get_option('fds_worker_interval', 30))), 
                            'fds_process_queue_worker', 
                            ['worker_id' => $i], 
                            'filebird-dropbox-sync'
                        );
                        $scheduled_workers++;
                        $this->logger->info("Scheduled worker $i for parallel processing");
                    } catch (Exception $e) {
                        $this->logger->error("Failed to schedule worker $i: " . $e->getMessage());
                    }
                }
                
                // Verify at least one worker was scheduled
                if ($scheduled_workers === 0) {
                    throw new Exception('Failed to schedule any worker processes');
                }
                
                // Schedule maintenance task
                try {
                    if (!as_next_scheduled_action('fds_weekly_maintenance', null, 'filebird-dropbox-sync')) {
                        as_schedule_recurring_action(
                            time(), 
                            WEEK_IN_SECONDS, 
                            'fds_weekly_maintenance', 
                            [], 
                            'filebird-dropbox-sync'
                        );
                        $this->logger->info("Scheduled weekly maintenance task");
                    } else {
                        $this->logger->info("Weekly maintenance task already scheduled");
                    }
                } catch (Exception $e) {
                    $this->logger->error("Failed to schedule maintenance task: " . $e->getMessage());
                    // Non-critical error, continue
                }
                
                // Schedule a delta sync task
                try {
                    $root_folder = sanitize_text_field(get_option('fds_root_dropbox_folder', '/Website'));
                    // Ensure root folder starts with / for Dropbox API
                    if (empty($root_folder) || $root_folder[0] !== '/') {
                        $root_folder = '/' . $root_folder;
                    }
                    
                    if (!as_next_scheduled_action('fds_daily_delta_sync', null, 'filebird-dropbox-sync')) {
                        as_schedule_recurring_action(
                            time(), 
                            DAY_IN_SECONDS, 
                            'fds_continue_delta_sync', 
                            ['path' => $root_folder], 
                            'filebird-dropbox-sync'
                        );
                        $this->logger->info("Scheduled daily delta sync for path: $root_folder");
                    } else {
                        $this->logger->info("Daily delta sync already scheduled");
                    }
                } catch (Exception $e) {
                    $this->logger->error("Failed to schedule delta sync: " . $e->getMessage());
                    // Non-critical error, continue
                }
                
                $this->logger->info("Action Scheduler setup completed successfully with $scheduled_workers workers");
                $action_scheduler_success = true;
                
            } catch (Exception $e) {
                // Log detailed error and fall back to WP Cron
                $this->logger->error("Error setting up Action Scheduler: " . $e->getMessage());
                $this->logger->error("Trace: " . $e->getTraceAsString());
                $action_scheduler_success = false;
            }
        } else {
            $this->logger->warning("Action Scheduler not available. Required for optimal performance.");
        }
        
        // If Action Scheduler setup failed or is not available, fall back to WP Cron
        if (!$action_scheduler_success) {
            $this->logger->info("Falling back to standard WP-Cron for queue processing");
            
            // Clean up any existing schedules to prevent duplicates
            $timestamp = wp_next_scheduled('fds_process_queue');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'fds_process_queue');
            }
            
            $maintenance_timestamp = wp_next_scheduled('fds_weekly_maintenance');
            if ($maintenance_timestamp) {
                wp_unschedule_event($maintenance_timestamp, 'fds_weekly_maintenance');
            }
            
            // Schedule with WP-Cron
            try {
                if (!wp_next_scheduled('fds_process_queue')) {
                    $scheduled = wp_schedule_event(time(), 'one_minute', 'fds_process_queue');
                    if ($scheduled === false) {
                        $this->logger->error("Failed to schedule queue processing with WP-Cron");
                    } else {
                        $this->logger->info("Scheduled queue processing with WP-Cron");
                    }
                }
                
                if (!wp_next_scheduled('fds_weekly_maintenance')) {
                    $scheduled = wp_schedule_event(time(), 'weekly', 'fds_weekly_maintenance');
                    if ($scheduled === false) {
                        $this->logger->error("Failed to schedule maintenance with WP-Cron");
                    } else {
                        $this->logger->info("Scheduled weekly maintenance with WP-Cron");
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error setting up WP-Cron fallback: " . $e->getMessage());
                // Ultimate fallback - show admin notice
                add_action('admin_notices', function() {
                    echo '<div class="error"><p>';
                    echo 'FileBird Dropbox Sync: Failed to setup background processing. Please check server configuration.';
                    echo '</p></div>';
                });
            }
        }
        
        return $action_scheduler_success;
    }
}