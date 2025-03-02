<?php
/**
 * Handles database operations for the plugin.
 *
 * This class provides methods to interact with the plugin's database tables.
 *
 * @since      1.0.0
 */
class FDS_DB {

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Get folder mapping by FileBird folder ID.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The FileBird folder ID.
     * @return   object|null             The folder mapping or null if not found.
     */
    public function get_folder_mapping_by_folder_id($folder_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
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
     * Delete file mapping by attachment ID.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   int|false                   The number of rows affected or false on error.
     */
    public function delete_file_mapping_by_attachment_id($attachment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_sync_queue';
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_logs';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_logs';
        
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_logs';
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}