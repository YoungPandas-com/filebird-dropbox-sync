<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 */
class FDS_Core {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The settings instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Settings    $settings    Manages plugin settings.
     */
    protected $settings;

    /**
     * The Dropbox API instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Dropbox_API    $dropbox_api    Handles Dropbox API integration.
     */
    protected $dropbox_api;

    /**
     * The database instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_DB    $db    Database operations.
     */
    protected $db;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    Logger for operations.
     */
    protected $logger;

    /**
     * The folder sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Folder_Sync    $folder_sync    Folder synchronization.
     */
    protected $folder_sync;

    /**
     * The file sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_File_Sync    $file_sync    File synchronization.
     */
    protected $file_sync;

    /**
     * The queue instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Queue    $queue    Queue system.
     */
    protected $queue;

    /**
     * The webhook instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Webhook    $webhook    Webhook handler.
     */
    protected $webhook;

    /**
     * The performance instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Performance    $performance    Performance optimization.
     */
    protected $performance;

    /**
     * The REST controller instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_REST_Controller    $rest_controller    REST API controller.
     */
    protected $rest_controller;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->load_dependencies();
        $this->set_locale();
        $this->initialize_components();
        $this->define_admin_hooks();
        $this->define_sync_hooks();
        
        // Add hook for post-activation tasks
        add_action('admin_init', array($this, 'check_post_activation_tasks'));
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-loader.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-i18n.php';

        /**
         * The class responsible for plugin settings.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-settings.php';

        /**
         * The class responsible for Dropbox API integration.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-dropbox-api.php';

        /**
         * The class responsible for folder synchronization.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-folder-sync.php';

        /**
         * The class responsible for file synchronization.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-file-sync.php';

        /**
         * The class responsible for queue system.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-queue.php';

        /**
         * The class responsible for webhook handling.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-webhook.php';

        /**
         * The class responsible for database operations.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-db.php';

        /**
         * The class responsible for handling errors and logging.
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-logger.php';

        /**
         * The class responsible for performace optimization
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-performance.php';

        /**
         * The class responsible for REST API controllers
         */
        require_once FDS_PLUGIN_DIR . 'includes/class-fds-rest-controller.php';

        $this->loader = new FDS_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the FDS_i18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function set_locale() {
        $plugin_i18n = new FDS_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Initialize plugin components.
     *
     * @since    1.0.0
     * @access   private
     */
    private function initialize_components() {
        // Start with the basic components that don't have dependencies
        $this->db = new FDS_DB();
        $this->logger = new FDS_Logger();
        
        // Initialize settings with the logger
        $this->settings = new FDS_Settings($this->logger);
        
        // Initialize Dropbox API with settings and logger
        $this->dropbox_api = new FDS_Dropbox_API($this->settings, $this->logger);
        
        // Initialize folder sync with its dependencies
        $this->folder_sync = new FDS_Folder_Sync($this->dropbox_api, $this->db, $this->logger);
        
        // Initialize file sync with its dependencies
        $this->file_sync = new FDS_File_Sync($this->dropbox_api, $this->db, $this->logger);
        
        // Initialize queue system with its dependencies
        $this->queue = new FDS_Queue($this->folder_sync, $this->file_sync, $this->logger);
        
        // Initialize webhook handler with its dependencies
        $this->webhook = new FDS_Webhook($this->queue, $this->dropbox_api, $this->settings, $this->logger);
        
        // Set the webhook reference in settings
        $this->settings->set_webhook($this->webhook);
        
        // Initialize REST controller with its dependencies
        $this->rest_controller = new FDS_REST_Controller($this->db, $this->logger, $this->queue);
        
        // Set the REST controller reference in settings
        $this->settings->set_rest_controller($this->rest_controller);
        
        // Initialize performance optimization with its dependencies
        $this->performance = new FDS_Performance($this->db, $this->logger);
        
        // Initialize Action Scheduler integration
        $this->initialize_action_scheduler();
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        // The hooks are already defined in each component's constructor
    }

    /**
     * Register all of the hooks related to the synchronization functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_sync_hooks() {
        // Register webhook endpoint
        $this->loader->add_action('rest_api_init', $this->webhook, 'register_webhook_endpoint');
        
        // FileBird folder hooks
        $this->loader->add_action('fbv_after_folder_created', $this->folder_sync, 'on_folder_created', 10, 2);
        $this->loader->add_action('fbv_after_folder_renamed', $this->folder_sync, 'on_folder_renamed', 10, 2);
        $this->loader->add_action('fbv_after_folder_deleted', $this->folder_sync, 'on_folder_deleted');
        $this->loader->add_action('fbv_after_parent_updated', $this->folder_sync, 'on_folder_moved', 10, 2);
        
        // WordPress attachment hooks
        $this->loader->add_action('add_attachment', $this->file_sync, 'on_file_added');
        $this->loader->add_action('delete_attachment', $this->file_sync, 'on_file_deleted');
        $this->loader->add_action('wp_update_attachment_metadata', $this->file_sync, 'on_file_updated', 10, 2);
        $this->loader->add_action('fbv_after_set_folder', $this->file_sync, 'on_file_moved', 10, 2);
        
        // WP-Cron hook for queue processing
        $this->loader->add_action('fds_process_queue', $this->queue, 'process_queued_items');
        
        // Register custom cron schedules
        $this->loader->add_filter('cron_schedules', $this, 'add_cron_schedules');
    }

    /**
     * Initialize Action Scheduler for background processing.
     *
     * @return bool True if Action Scheduler is available and initialized, false otherwise.
     */
    public function initialize_action_scheduler() {
        // Check if Action Scheduler is available
        if (!class_exists('ActionScheduler')) {
            // Schedule regular cron events instead
            if (!wp_next_scheduled('fds_process_queue')) {
                wp_schedule_event(time(), 'one_minute', 'fds_process_queue');
            }
            return false;
        }
        
        // Register our hook for processing queue items
        add_action('fds_process_queue_worker', array($this->queue, 'process_worker_queue'), 10, 2);
        
        // Register our hook for delta sync
        add_action('fds_continue_delta_sync', array($this->performance, 'delta_sync'), 10, 2);
        
        // Register our hook for weekly maintenance
        add_action('fds_weekly_maintenance', array($this->performance, 'optimize_database_tables'));
        
        // Setup parallel processing if the method exists
        if (method_exists($this->performance, 'setup_parallel_processing')) {
            $this->performance->setup_parallel_processing();
        }
        
        return true;
    }

    /**
     * Add custom cron schedules.
     *
     * @since    1.0.0
     * @param    array    $schedules    Current cron schedules.
     * @return   array                  Modified cron schedules.
     */
    public function add_cron_schedules($schedules) {
        $schedules['one_minute'] = array(
            'interval' => 60,
            'display'  => __('Every Minute', 'filebird-dropbox-sync'),
        );
        return $schedules;
    }

    /**
     * Check for post-activation tasks
     */
    public function check_post_activation_tasks() {
        // Check if we need to run a full sync after activation
        if (get_option('fds_run_full_sync_after_activation', false)) {
            delete_option('fds_run_full_sync_after_activation');
            
            // Make sure all components are initialized
            if (isset($this->queue) && isset($this->logger)) {
                $this->logger->info("Running full sync after activation");
                $this->queue->start_full_sync();
            }
        }
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }
}