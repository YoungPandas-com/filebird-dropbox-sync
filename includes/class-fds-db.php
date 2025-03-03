<?php
/**
 * Handles database operations for the plugin with improved error handling.
 *
 * This class provides methods to interact with the plugin's database tables.
 *
 * @since      1.0.0
 */
class FDS_DB {

    /**
     * Required database tables.
     *
     * @since    1.0.0
     * @access   protected
     * @var      array    $required_tables    List of required tables.
     */
    protected $required_tables = array();

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     */
    public function __construct() {
        global $wpdb;
        
        // Define required tables
        $this->required_tables = array(
            'folder_mapping' => $wpdb->prefix . 'fds_folder_mapping',
            'file_mapping' => $wpdb->prefix . 'fds_file_mapping',
            'sync_queue' => $wpdb->prefix . 'fds_sync_queue',
            'logs' => $wpdb->prefix . 'fds_logs',
            'cache' => $wpdb->prefix . 'fds_cache'
        );
    }

    /**
     * Check if a specific table exists.
     *
     * @since    1.0.0
     * @param    string    $table_key    The table key from $required_tables.
     * @return   boolean                True if table exists, false otherwise.
     */
    public function table_exists($table_key) {
        if (!isset($this->required_tables[$table_key])) {
            return false;
        }

        global $wpdb;
        $table_name = $this->required_tables[$table_key];
        
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        return ($result === $table_name);
    }

    /**
     * Check if all required tables exist.
     *
     * @since    1.0.0
     * @return   boolean    True if all tables exist, false otherwise.
     */
    public function all_tables_exist() {
        foreach (array_keys($this->required_tables) as $table_key) {
            if (!$this->table_exists($table_key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create required tables if they don't exist.
     *
     * @since    1.0.0
     * @return   boolean    True on success, false on failure.
     */
    public function create_tables_if_needed() {
        if ($this->all_tables_exist()) {
            return true;
        }

        // Include the activator class to create tables
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-activator.php';
        
        // Run the activation function to create tables
        FDS_Activator::activate();
        
        // Verify tables were created
        return $this->all_tables_exist();
    }

    /**
     * Get folder mapping by FileBird folder ID with table existence check.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The FileBird folder ID.
     * @return   object|null             The folder mapping or null if not found.
     */
    public function get_folder_mapping_by_folder_id($folder_id) {
        if (!$this->table_exists('folder_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('folder_mapping')) {
                return null;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE filebird_folder_id = %d",
                $folder_id
            )
        );
    }

    /**
     * Get folder mapping by Dropbox path.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox path.
     * @return   object|null                The folder mapping or null if not found.
     */
    public function get_folder_mapping_by_dropbox_path($dropbox_path) {
        if (!$this->table_exists('folder_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('folder_mapping')) {
                return null;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE dropbox_path = %s",
                $dropbox_path
            )
        );
    }

    /**
     * Add or update folder mapping.
     *
     * @since    1.0.0
     * @param    int       $folder_id       The FileBird folder ID.
     * @param    string    $dropbox_path    The Dropbox path.
     * @param    string    $sync_hash       The synchronization hash.
     * @return   int|false                  The number of rows affected or false on error.
     */
    public function add_or_update_folder_mapping($folder_id, $dropbox_path, $sync_hash) {
        if (!$this->table_exists('folder_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('folder_mapping')) {
                return false;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        $existing = $this->get_folder_mapping_by_folder_id($folder_id);
        
        $data = array(
            'filebird_folder_id' => $folder_id,
            'dropbox_path' => $dropbox_path,
            'last_synced' => current_time('mysql'),
            'sync_hash' => $sync_hash,
        );
        
        if ($existing) {
            return $wpdb->update(
                $table_name,
                $data,
                array('filebird_folder_id' => $folder_id),
                array('%d', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            return $wpdb->insert(
                $table_name,
                $data,
                array('%d', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Delete folder mapping by FileBird folder ID.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The FileBird folder ID.
     * @return   int|false               The number of rows affected or false on error.
     */
    public function delete_folder_mapping_by_folder_id($folder_id) {
        if (!$this->table_exists('folder_mapping')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        
        return $wpdb->delete(
            $table_name,
            array('filebird_folder_id' => $folder_id),
            array('%d')
        );
    }

    /**
     * Delete folder mapping by Dropbox path.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox path.
     * @return   int|false                  The number of rows affected or false on error.
     */
    public function delete_folder_mapping_by_dropbox_path($dropbox_path) {
        if (!$this->table_exists('folder_mapping')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        
        return $wpdb->delete(
            $table_name,
            array('dropbox_path' => $dropbox_path),
            array('%s')
        );
    }

    /**
     * Get all folder mappings.
     *
     * @since    1.0.0
     * @return   array    The folder mappings.
     */
    public function get_all_folder_mappings() {
        if (!$this->table_exists('folder_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('folder_mapping')) {
                return array();
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['folder_mapping'];
        
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY filebird_folder_id ASC");
    }

    /**
     * Get file mapping by attachment ID.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   object|null                 The file mapping or null if not found.
     */
    public function get_file_mapping_by_attachment_id($attachment_id) {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return null;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE attachment_id = %d",
                $attachment_id
            )
        );
    }

    /**
     * Get file mapping by Dropbox file ID.
     *
     * @since    1.0.0
     * @param    string    $dropbox_file_id    The Dropbox file ID.
     * @return   object|null                   The file mapping or null if not found.
     */
    public function get_file_mapping_by_dropbox_file_id($dropbox_file_id) {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return null;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE dropbox_file_id = %s",
                $dropbox_file_id
            )
        );
    }

    /**
     * Get file mapping by Dropbox path.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox path.
     * @return   object|null                The file mapping or null if not found.
     */
    public function get_file_mapping_by_dropbox_path($dropbox_path) {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return null;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE dropbox_path = %s",
                $dropbox_path
            )
        );
    }

    /**
     * Add or update file mapping.
     *
     * @since    1.0.0
     * @param    int       $attachment_id     The attachment ID.
     * @param    string    $dropbox_path      The Dropbox path.
     * @param    string    $dropbox_file_id   The Dropbox file ID.
     * @param    string    $sync_hash         The synchronization hash.
     * @return   int|false                    The number of rows affected or false on error.
     */
    public function add_or_update_file_mapping($attachment_id, $dropbox_path, $dropbox_file_id, $sync_hash) {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return false;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        $existing = $this->get_file_mapping_by_attachment_id($attachment_id);
        
        $data = array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $dropbox_path,
            'dropbox_file_id' => $dropbox_file_id,
            'last_synced' => current_time('mysql'),
            'sync_hash' => $sync_hash,
        );
        
        if ($existing) {
            return $wpdb->update(
                $table_name,
                $data,
                array('attachment_id' => $attachment_id),
                array('%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            return $wpdb->insert(
                $table_name,
                $data,
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
    }
    
    /**
     * Add or update multiple file mappings in a single transaction.
     *
     * @since    1.0.0
     * @param    array    $mappings    Array of mapping data [attachment_id => [dropbox_path, dropbox_file_id, sync_hash]]
     * @return   int|false             The number of rows affected or false on error.
     */
    public function batch_update_file_mappings($mappings) {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return false;
            }
        }

        global $wpdb;
        
        if (empty($mappings)) {
            return 0;
        }
        
        $table_name = $this->required_tables['file_mapping'];
        $rows_affected = 0;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($mappings as $attachment_id => $data) {
                if (empty($data['dropbox_path']) || empty($data['dropbox_file_id']) || empty($data['sync_hash'])) {
                    continue;
                }
                
                $existing = $this->get_file_mapping_by_attachment_id($attachment_id);
                
                $mapping_data = array(
                    'attachment_id' => $attachment_id,
                    'dropbox_path' => $data['dropbox_path'],
                    'dropbox_path_hash' => md5($data['dropbox_path']),
                    'dropbox_file_id' => $data['dropbox_file_id'],
                    'last_synced' => current_time('mysql'),
                    'sync_hash' => $data['sync_hash'],
                );
                
                if ($existing) {
                    $result = $wpdb->update(
                        $table_name,
                        $mapping_data,
                        array('attachment_id' => $attachment_id)
                    );
                } else {
                    $result = $wpdb->insert($table_name, $mapping_data);
                }
                
                if ($result === false) {
                    throw new Exception("Database error: " . $wpdb->last_error);
                }
                
                $rows_affected += $result;
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return $rows_affected;
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            
            // Log error
            error_log("Batch update file mappings failed: " . $e->getMessage());
            
            return false;
        }
    }

    /**
     * Delete file mapping by attachment ID.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   int|false                   The number of rows affected or false on error.
     */
    public function delete_file_mapping_by_attachment_id($attachment_id) {
        if (!$this->table_exists('file_mapping')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->delete(
            $table_name,
            array('attachment_id' => $attachment_id),
            array('%d')
        );
    }

    /**
     * Delete file mapping by Dropbox file ID.
     *
     * @since    1.0.0
     * @param    string    $dropbox_file_id    The Dropbox file ID.
     * @return   int|false                     The number of rows affected or false on error.
     */
    public function delete_file_mapping_by_dropbox_file_id($dropbox_file_id) {
        if (!$this->table_exists('file_mapping')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->delete(
            $table_name,
            array('dropbox_file_id' => $dropbox_file_id),
            array('%s')
        );
    }

    /**
     * Get all file mappings.
     *
     * @since    1.0.0
     * @return   array    The file mappings.
     */
    public function get_all_file_mappings() {
        if (!$this->table_exists('file_mapping')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('file_mapping')) {
                return array();
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['file_mapping'];
        
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY attachment_id ASC");
    }

    /**
     * Add task to the sync queue.
     *
     * @since    1.0.0
     * @param    string    $action       The action to perform (create, update, delete, move).
     * @param    string    $item_type    The item type (folder or file).
     * @param    string    $item_id      The item ID.
     * @param    string    $direction    The synchronization direction (wordpress_to_dropbox or dropbox_to_wordpress).
     * @param    array     $data         Additional data for the task.
     * @param    int       $priority     The task priority (lower numbers = higher priority).
     * @return   int|false               The task ID or false on error.
     */
    public function add_to_sync_queue($action, $item_type, $item_id, $direction, $data = array(), $priority = 10) {
        if (!$this->table_exists('sync_queue')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('sync_queue')) {
                return false;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['sync_queue'];
        
        // Check if a similar task already exists and is pending
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM $table_name WHERE action = %s AND item_type = %s AND item_id = %s AND direction = %s AND status = 'pending'",
                $action,
                $item_type,
                $item_id,
                $direction
            )
        );
        
        if ($existing) {
            // Update existing task with new data and reset attempts
            return $wpdb->update(
                $table_name,
                array(
                    'data' => maybe_serialize($data),
                    'priority' => $priority,
                    'attempts' => 0,
                    'error_message' => '',
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $existing->id),
                array('%s', '%d', '%d', '%s', '%s'),
                array('%d')
            );
        } else {
            // Insert new task
            $result = $wpdb->insert(
                $table_name,
                array(
                    'action' => $action,
                    'item_type' => $item_type,
                    'item_id' => $item_id,
                    'direction' => $direction,
                    'data' => maybe_serialize($data),
                    'priority' => $priority,
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s')
            );
            
            return $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Get pending tasks from the sync queue.
     *
     * @since    1.0.0
     * @param    int       $limit    The maximum number of tasks to get.
     * @return   array               The pending tasks.
     */
    public function get_pending_tasks($limit = 10) {
        if (!$this->table_exists('sync_queue')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('sync_queue')) {
                return array();
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['sync_queue'];
        $max_retries = get_option('fds_max_retries', 3);
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE status = 'pending' AND attempts < %d ORDER BY priority ASC, created_at ASC LIMIT %d",
                $max_retries,
                $limit
            )
        );
    }

    /**
     * Update task status in the sync queue.
     *
     * @since    1.0.0
     * @param    int       $task_id        The task ID.
     * @param    string    $status         The new status (pending, processing, completed, failed).
     * @param    string    $error_message  The error message if applicable.
     * @return   int|false                 The number of rows affected or false on error.
     */
    public function update_task_status($task_id, $status, $error_message = '') {
        if (!$this->table_exists('sync_queue')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['sync_queue'];
        
        $data = array(
            'status' => $status,
            'updated_at' => current_time('mysql'),
        );
        
        if ($status === 'processing') {
            $data['attempts'] = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT attempts FROM $table_name WHERE id = %d",
                    $task_id
                )
            ) + 1;
        }
        
        if (!empty($error_message)) {
            $data['error_message'] = $error_message;
        }
        
        return $wpdb->update(
            $table_name,
            $data,
            array('id' => $task_id),
            array('%s', '%s', '%d', '%s'),
            array('%d')
        );
    }

    /**
     * Delete completed tasks older than a certain time.
     *
     * @since    1.0.0
     * @param    int       $days    The number of days to keep completed tasks.
     * @return   int|false          The number of rows affected or false on error.
     */
    public function cleanup_completed_tasks($days = 7) {
        if (!$this->table_exists('sync_queue')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['sync_queue'];
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE status = 'completed' AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Add log entry.
     *
     * @since    1.0.0
     * @param    string    $level     The log level (emergency, alert, critical, error, warning, notice, info, debug).
     * @param    string    $message   The log message.
     * @param    array     $context   Additional context data.
     * @return   int|false            The log ID or false on error.
     */
    public function add_log($level, $message, $context = array()) {
        if (!$this->table_exists('logs')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('logs')) {
                // If we still can't create the log table, fall back to error_log
                error_log(sprintf('[FileBird Dropbox Sync] [%s] %s', strtoupper($level), $message));
                return false;
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['logs'];
        
        return $wpdb->insert(
            $table_name,
            array(
                'level' => $level,
                'message' => $message,
                'context' => !empty($context) ? maybe_serialize($context) : '',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get logs.
     *
     * @since    1.0.0
     * @param    string    $level    The minimum log level to retrieve.
     * @param    int       $limit    The maximum number of logs to get.
     * @param    int       $offset   The offset for pagination.
     * @return   array               The logs.
     */
    public function get_logs($level = '', $limit = 100, $offset = 0) {
        if (!$this->table_exists('logs')) {
            $this->create_tables_if_needed();
            if (!$this->table_exists('logs')) {
                return array();
            }
        }

        global $wpdb;
        
        $table_name = $this->required_tables['logs'];
        
        $sql = "SELECT * FROM $table_name";
        $args = array();
        
        if (!empty($level)) {
            $levels = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug');
            $level_index = array_search($level, $levels);
            
            if ($level_index !== false) {
                $sql .= " WHERE level IN ('" . implode("','", array_slice($levels, 0, $level_index + 1)) . "')";
            }
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;
        
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    /**
     * Delete old logs.
     *
     * @since    1.0.0
     * @param    int       $days    The number of days to keep logs.
     * @return   int|false          The number of rows affected or false on error.
     */
    public function cleanup_logs($days = 30) {
        if (!$this->table_exists('logs')) {
            return false;
        }

        global $wpdb;
        
        $table_name = $this->required_tables['logs'];
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Perform maintenance operations on database tables.
     *
     * @since    1.0.0
     * @return   array|false    Summary of operations performed or false on error.
     */
    public function perform_maintenance() {
        if (!$this->all_tables_exist()) {
            $this->create_tables_if_needed();
            if (!$this->all_tables_exist()) {
                return false;
            }
        }

        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Clean up completed tasks older than 3 days
            $completed_deleted = $this->cleanup_completed_tasks(3);
            
            // Clean up failed tasks older than 7 days
            $table_name = $this->required_tables['sync_queue'];
            $failed_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE status = 'failed' AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    7
                )
            );
            
            // Clean up logs older than 30 days
            $logs_deleted = $this->cleanup_logs(30);
            
            // Clean up cache
            $cache_table = $this->required_tables['cache'];
            $cache_deleted = $wpdb->query(
                "DELETE FROM $cache_table WHERE expires_at < NOW()"
            );
            
            // Optimize tables
            foreach ($this->required_tables as $table) {
                $wpdb->query("OPTIMIZE TABLE $table");
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return [
                'completed_tasks_deleted' => $completed_deleted,
                'failed_tasks_deleted' => $failed_deleted,
                'logs_deleted' => $logs_deleted,
                'cache_entries_deleted' => $cache_deleted
            ];
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log("Database maintenance failed: " . $e->getMessage());
            return false;
        }
    }
}