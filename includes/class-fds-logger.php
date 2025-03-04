<?php
/**
 * Handles logging for the plugin.
 *
 * This class provides methods for logging events and errors.
 *
 * @since      1.0.0
 */
class FDS_Logger {

    /**
     * The database instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_DB    $db    The database instance.
     */
    protected $db;

    /**
     * Log levels from highest to lowest priority.
     *
     * @since    1.0.0
     * @access   protected
     * @var      array    $levels    The log levels.
     */
    protected $levels = array(
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    );

    /**
     * Flag to indicate if database logging is initialized.
     * 
     * @since    1.0.0
     * @access   protected
     * @var      boolean    $db_initialized    Whether database logging is initialized.
     */
    protected $db_initialized = false;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    FDS_DB    $db    The database instance (optional).
     */
    public function __construct($db = null) {
        // Store the database instance if provided
        if ($db) {
            $this->db = $db;
            $this->db_initialized = true;
        }
        // We'll initialize the DB later if needed, to avoid circular dependencies
    }

    /**
     * Set the database instance.
     *
     * @since    1.0.0
     * @param    FDS_DB    $db    The database instance.
     */
    public function set_db($db) {
        $this->db = $db;
        $this->db_initialized = true;
    }

    /**
     * Ensure database is initialized for logging.
     *
     * @since    1.0.0
     */
    protected function ensure_db_initialized() {
        if (!$this->db_initialized) {
            $this->db = new FDS_DB();
            $this->db_initialized = true;
        }
    }

    /**
     * Log an emergency message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function emergency($message, $context = array()) {
        $this->log('emergency', $message, $context);
    }

    /**
     * Log an alert message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function alert($message, $context = array()) {
        $this->log('alert', $message, $context);
    }

    /**
     * Log a critical message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function critical($message, $context = array()) {
        $this->log('critical', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }

    /**
     * Log a notice message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function notice($message, $context = array()) {
        $this->log('notice', $message, $context);
    }

    /**
     * Log an info message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }

    /**
     * Log a debug message.
     *
     * @since    1.0.0
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function debug($message, $context = array()) {
        $this->log('debug', $message, $context);
    }

    /**
     * Log a message.
     *
     * @since    1.0.0
     * @param    string    $level      The log level.
     * @param    string    $message    The log message.
     * @param    array     $context    Additional context data.
     */
    public function log($level, $message, $context = array()) {
        // Check if the log level is valid
        if (!in_array($level, $this->levels)) {
            $level = 'info';
        }
        
        // Check if the log level is enabled
        $configured_level = get_option('fds_log_level', 'error');
        $configured_level_index = array_search($configured_level, $this->levels);
        $level_index = array_search($level, $this->levels);
        
        if ($level_index > $configured_level_index) {
            return;
        }
        
        // Add timestamp to context
        $context['timestamp'] = current_time('mysql');
        
        // Add user info to context if available
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $context['user'] = array(
                'id' => $current_user->ID,
                'login' => $current_user->user_login,
            );
        }
        
        // Process message to handle exceptions and include stack traces
        if ($message instanceof Exception) {
            $context['exception'] = array(
                'class' => get_class($message),
                'message' => $message->getMessage(),
                'code' => $message->getCode(),
                'file' => $message->getFile(),
                'line' => $message->getLine(),
                'trace' => $message->getTraceAsString(),
            );
            $message = sprintf('%s: %s in %s:%s', get_class($message), $message->getMessage(), $message->getFile(), $message->getLine());
        }
        
        // Always ensure DB is initialized - THIS IS THE FIX
        $this->ensure_db_initialized();
        
        // Add to database logs if DB is available
        if ($this->db_initialized) {
            try {
                $this->db->add_log($level, $message, $context);
            } catch (Exception $e) {
                // Fallback to PHP error log if DB logging fails
                error_log(sprintf('[FileBird Dropbox Sync] [%s] %s - DB logging failed: %s', 
                    strtoupper($level), 
                    $message, 
                    $e->getMessage()
                ));
            }
        } else {
            // Always log to PHP error log during initialization
            error_log(sprintf('[FileBird Dropbox Sync] [%s] %s', strtoupper($level), $message));
        }
        
        // For emergency/critical/errors, also log to PHP error log
        if (in_array($level, array('emergency', 'alert', 'critical', 'error'))) {
            error_log(sprintf('[FileBird Dropbox Sync] [%s] %s', strtoupper($level), $message));
        }
        
        // Cleanup old logs occasionally (randomly to avoid doing it on every request)
        if ($this->db_initialized && mt_rand(1, 1000) === 1) {
            $this->cleanup_logs();
        }
    }

    /**
     * Get logs from the database.
     *
     * @since    1.0.0
     * @param    string    $level     The minimum log level to retrieve.
     * @param    int       $limit     The maximum number of logs to get.
     * @param    int       $offset    The offset for pagination.
     * @return   array                The logs.
     */
    public function get_logs($level = '', $limit = 100, $offset = 0) {
        $this->ensure_db_initialized();
        return $this->db->get_logs($level, $limit, $offset);
    }

    /**
     * Clean up old logs.
     *
     * @since    1.0.0
     * @param    int    $days    The number of days to keep logs.
     */
    public function cleanup_logs($days = 30) {
        $this->ensure_db_initialized();
        $this->db->cleanup_logs($days);
    }
}