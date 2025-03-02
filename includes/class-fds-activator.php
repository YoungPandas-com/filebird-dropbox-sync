<?php
/**
 * Enhanced database structure for high-performance synchronization.
 *
 * This class creates optimized database tables for handling 100,000+ files efficiently.
 *
 * @since      1.0.0
 */
class FDS_Activator {

    /**
     * Create optimized database tables and indexes with improved error handling.
     */
    public static function activate() {
        global $wpdb;
        
        // Check FileBird dependency
        if (!class_exists('FileBird\\Model\\Folder')) {
            // Log the missing dependency
            self::log_activation_error('FileBird plugin is not active. Please install and activate FileBird first.');
            return;
        }
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Create folders mapping table with optimized structure and indexes
        $table_name = $wpdb->prefix . 'fds_folder_mapping';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            filebird_folder_id bigint(20) NOT NULL,
            dropbox_path varchar(768) NOT NULL,
            dropbox_path_hash varchar(32) NOT NULL,
            last_synced datetime DEFAULT CURRENT_TIMESTAMP,
            sync_hash varchar(64) NOT NULL,
            sync_status varchar(20) DEFAULT 'synced',
            metadata longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY filebird_folder_id (filebird_folder_id),
            UNIQUE KEY dropbox_path_hash (dropbox_path_hash),
            KEY dropbox_path (dropbox_path(191)),
            KEY sync_status (sync_status),
            KEY last_synced (last_synced)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Create files mapping table with optimized structure for large datasets
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            dropbox_path varchar(768) NOT NULL,
            dropbox_path_hash varchar(32) NOT NULL,
            dropbox_file_id varchar(255) NOT NULL,
            dropbox_content_hash varchar(64),
            filebird_folder_id bigint(20) DEFAULT 0,
            file_size bigint(20) DEFAULT 0,
            last_synced datetime DEFAULT CURRENT_TIMESTAMP,
            sync_hash varchar(64) NOT NULL,
            sync_status varchar(20) DEFAULT 'synced',
            metadata longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY attachment_id (attachment_id),
            UNIQUE KEY dropbox_path_hash (dropbox_path_hash),
            UNIQUE KEY dropbox_file_id (dropbox_file_id),
            KEY dropbox_path (dropbox_path(191)),
            KEY filebird_folder_id (filebird_folder_id),
            KEY sync_status (sync_status),
            KEY last_synced (last_synced)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Create sync queue table with partitioning by status for better query performance
        $table_name = $wpdb->prefix . 'fds_sync_queue';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            item_type varchar(10) NOT NULL,
            item_id varchar(255) NOT NULL,
            direction varchar(20) NOT NULL,
            data longtext,
            priority int(11) DEFAULT 10,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            error_message text,
            worker_id int(11) DEFAULT 0,
            locked_at datetime,
            locked_by varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status_priority (status, priority),
            KEY item_type_id (item_type, item_id(191)),
            KEY worker_id (worker_id),
            KEY locked_at (locked_at),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Create logs table with partitioning by level and date
        $table_name = $wpdb->prefix . 'fds_logs';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            component varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level_created (level, created_at),
            KEY component (component),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Create a cache table for frequently accessed data
        $table_name = $wpdb->prefix . 'fds_cache';
        
        $sql = "CREATE TABLE $table_name (
            cache_key varchar(255) NOT NULL,
            cache_value longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (cache_key),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Create a sync status table for monitoring and recovery
        $table_name = $wpdb->prefix . 'fds_sync_status';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'idle',
            last_run datetime,
            next_run datetime,
            last_cursor varchar(255),
            stats longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY sync_type (sync_type),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created
        if (!self::table_exists($table_name)) {
            self::log_activation_error("Failed to create table: $table_name");
        }
        
        // Add custom capabilities
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('manage_fds_settings');
        }
        
        // Add default options with optimized settings
        add_option('fds_conflict_resolution', 'wordpress_wins');
        add_option('fds_sync_enabled', 0);
        add_option('fds_dropbox_access_token', '');
        add_option('fds_dropbox_refresh_token', '');
        add_option('fds_dropbox_token_expiry', '');
        add_option('fds_queue_batch_size', 50); // Increased batch size
        add_option('fds_max_retries', 5); // Increased retry attempts
        add_option('fds_chunk_size', 8388608); // 8MB chunks for large files
        add_option('fds_worker_count', 5); // Number of parallel workers
        add_option('fds_memory_limit', '256M'); // Recommended memory limit
        
        // Create the uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-temp';
        if (!file_exists($fds_dir)) {
            wp_mkdir_p($fds_dir);
        }
        
        // Create a temp directory for chunked transfers
        $chunks_dir = $fds_dir . '/chunks';
        if (!file_exists($chunks_dir)) {
            wp_mkdir_p($chunks_dir);
        }
        
        // Create an .htaccess file to protect the temp directory
        $htaccess_file = $fds_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Deny from all\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        // Create an index.php file for extra protection
        $index_file = $fds_dir . '/index.php';
        if (!file_exists($index_file)) {
            $index_content = "<?php\n// Silence is golden.\n";
            file_put_contents($index_file, $index_content);
        }
        
        // Check for Action Scheduler
        self::check_for_action_scheduler();
        
        // Store a flag that we've completed activation
        update_option('fds_activated', time());
        
        // Log activation success
        self::log_activation_message('FileBird Dropbox Sync plugin activated successfully');
    }
    
    /**
     * Check if Action Scheduler is available and log appropriate message.
     */
    private static function check_for_action_scheduler() {
        if (!class_exists('ActionScheduler')) {
            // Log that Action Scheduler is not available - plugin will use WP-Cron instead
            self::log_activation_message('Action Scheduler not available. Using WP-Cron for background processing, which may be less reliable for large media libraries.');
            
            // Store option for graceful degradation
            update_option('fds_use_action_scheduler', false);
        } else {
            // Action Scheduler is available
            self::log_activation_message('Action Scheduler detected. Using optimized background processing for better performance.');
            
            // Store option to use Action Scheduler
            update_option('fds_use_action_scheduler', true);
        }
    }
    
    /**
     * Check if a table exists.
     * 
     * @param string $table_name Full table name.
     * @return bool True if table exists, false otherwise.
     */
    private static function table_exists($table_name) {
        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        return $result === $table_name;
    }
    
    /**
     * Log an activation error.
     * 
     * @param string $message Error message.
     */
    private static function log_activation_error($message) {
        // Write to WP error log
        error_log('[FileBird Dropbox Sync] Activation Error: ' . $message);
        
        // Store in plugin's own option for admin notices
        $errors = get_option('fds_activation_errors', array());
        $errors[] = array(
            'message' => $message,
            'time' => current_time('mysql')
        );
        update_option('fds_activation_errors', $errors);
    }
    
    /**
     * Log an activation message.
     * 
     * @param string $message Information message.
     */
    private static function log_activation_message($message) {
        // Write to WP error log
        error_log('[FileBird Dropbox Sync] Activation: ' . $message);
        
        // Store in plugin's own option for admin notices
        $messages = get_option('fds_activation_messages', array());
        $messages[] = array(
            'message' => $message,
            'time' => current_time('mysql')
        );
        update_option('fds_activation_messages', $messages);
    }
}