<?php
/**
 * The admin-specific functionality of the plugin with improved UI.
 *
 * Defines settings page, admin-specific hooks, and settings API integration.
 *
 * @since      1.0.0
 */
class FDS_Settings {

    /**
     * The REST controller instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_REST_Controller    $rest_controller    The REST controller instance.
     */
    protected $rest_controller;

    /**
     * The webhook instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Webhook    $webhook    The webhook instance.
     */
    protected $webhook;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    The logger instance.
     */
    protected $logger;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    FDS_Logger    $logger    The logger instance (optional).
     */
    public function __construct($logger = null) {
        // Add hooks for admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add hooks for settings page and registration
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add hooks for custom CSS and form elements
        add_action('admin_head', array($this, 'add_settings_css'));
        
        // Initialize or set the logger
        $this->logger = $logger ?: new FDS_Logger();
    }

    /**
     * Set the REST controller instance.
     *
     * @since    1.0.0
     * @param    FDS_REST_Controller    $rest_controller    The REST controller instance.
     */
    public function set_rest_controller($rest_controller) {
        $this->rest_controller = $rest_controller;
    }

    /**
     * Set the webhook instance.
     *
     * @since    1.0.0
     * @param    FDS_Webhook    $webhook    The webhook instance.
     */
    public function set_webhook($webhook) {
        $this->webhook = $webhook;
        
        // Set up webhook AJAX handlers - Do this here to avoid circular dependencies
        if ($this->webhook) {
            add_action('wp_ajax_fds_register_webhook', array($this->webhook, 'ajax_register_webhook'));
            add_action('wp_ajax_fds_test_webhook', array($this->webhook, 'ajax_test_webhook'));
            add_action('wp_ajax_fds_dismiss_welcome_notice', array($this, 'ajax_dismiss_welcome_notice'));
            
            // Add action hook for processing Dropbox changes
            add_action('fds_process_dropbox_changes', array($this->webhook, 'process_dropbox_changes'));
        }
    }

    /**
     * Add settings page to admin menu.
     *
     * @since    1.0.0
     */
    public function add_settings_page() {
        add_submenu_page(
            'upload.php', // Place it under Media menu for better discoverability
            __('FileBird Dropbox Sync', 'filebird-dropbox-sync'),
            __('FileBird Dropbox Sync', 'filebird-dropbox-sync'),
            'manage_options', // Use standard WordPress capability
            'filebird-dropbox-sync-settings',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles($hook) {
        // Only enqueue on our settings page
        if ('media_page_filebird-dropbox-sync-settings' !== $hook) {
            return;
        }
        
        // Enqueue core WordPress styles
        wp_enqueue_style('wp-components');
        
        // Enqueue our custom stylesheet
        wp_enqueue_style('fds-admin', FDS_PLUGIN_URL . 'admin/css/fds-admin.css', array(), FDS_VERSION, 'all');
        
        // Add Dashicons for better iconography
        wp_enqueue_style('dashicons');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts($hook) {
        // Only enqueue on our settings page
        if ('media_page_filebird-dropbox-sync-settings' !== $hook) {
            return;
        }
        
        // Enqueue jQuery UI for better UI interactions
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-tooltip');
        wp_enqueue_script('jquery-effects-highlight');
        
        // Enqueue our custom script
        wp_enqueue_script('fds-admin', FDS_PLUGIN_URL . 'admin/js/fds-admin.js', array('jquery', 'jquery-ui-core'), FDS_VERSION, false);
        
        // Localize our script with essential data
        wp_localize_script('fds-admin', 'fds_admin_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => get_rest_url(null, 'fds/v1'),
            'nonce' => wp_create_nonce('fds-admin-nonce'),
            'strings' => array(
                'confirm_sync' => __('Are you sure you want to start a full synchronization? This may take a while for large libraries.', 'filebird-dropbox-sync'),
                'sync_started' => __('Synchronization started. This process will continue in the background.', 'filebird-dropbox-sync'),
                'connecting' => __('Connecting to Dropbox...', 'filebird-dropbox-sync'),
                'connected' => __('Successfully connected to Dropbox!', 'filebird-dropbox-sync'),
                'error' => __('An error occurred:', 'filebird-dropbox-sync'),
                'confirm_disconnect' => __('Are you sure you want to disconnect from Dropbox? This will stop synchronization until you reconnect.', 'filebird-dropbox-sync'),
                'copy_success' => __('Copied to clipboard!', 'filebird-dropbox-sync'),
                'copy_failed' => __('Failed to copy. Please try manually selecting and copying the text.', 'filebird-dropbox-sync'),
            ),
            'is_connected' => $this->is_connected_to_dropbox(),
        ));
    }

    /**
     * Add a welcome notice to guide new users.
     * 
     * @since    1.0.0
     */
    public function add_welcome_notice() {
        // Check if we should show the notice
        if (!get_option('fds_welcome_notice_dismissed', false) && isset($_GET['page']) && $_GET['page'] === 'filebird-dropbox-sync-settings') {
            ?>
            <div class="notice notice-info is-dismissible fds-welcome-notice">
                <h3><?php _e('Welcome to FileBird Dropbox Sync!', 'filebird-dropbox-sync'); ?></h3>
                <p><?php _e('Thank you for installing FileBird Dropbox Sync. This plugin allows you to synchronize your WordPress media library with Dropbox.', 'filebird-dropbox-sync'); ?></p>
                <p><?php _e('To get started, follow these steps:', 'filebird-dropbox-sync'); ?></p>
                <ol>
                    <li><?php _e('Create a Dropbox app in the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox Developer Console</a>', 'filebird-dropbox-sync'); ?></li>
                    <li><?php _e('Enter your Dropbox App Key and App Secret in the Dropbox Connection tab', 'filebird-dropbox-sync'); ?></li>
                    <li><?php _e('Connect to Dropbox by clicking the "Connect to Dropbox" button', 'filebird-dropbox-sync'); ?></li>
                    <li><?php _e('Enable synchronization in the General tab and start syncing your files', 'filebird-dropbox-sync'); ?></li>
                </ol>
                <p>
                    <a href="?page=filebird-dropbox-sync-settings&tab=dropbox" class="button button-primary"><?php _e('Go to Dropbox Connection', 'filebird-dropbox-sync'); ?></a>
                    <a href="#" class="button fds-dismiss-welcome"><?php _e('Dismiss this notice', 'filebird-dropbox-sync'); ?></a>
                </p>
            </div>
            <script>
                jQuery(document).ready(function($) {
                    $('.fds-dismiss-welcome').on('click', function(e) {
                        e.preventDefault();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'fds_dismiss_welcome_notice',
                                nonce: '<?php echo wp_create_nonce('fds-dismiss-welcome'); ?>'
                            }
                        });
                        
                        $('.fds-welcome-notice').fadeOut();
                    });
                    
                    $(document).on('click', '.fds-welcome-notice .notice-dismiss', function() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'fds_dismiss_welcome_notice',
                                nonce: '<?php echo wp_create_nonce('fds-dismiss-welcome'); ?>'
                            }
                        });
                    });
                });
            </script>
            <?php
        }
    }

    /**
     * Handle AJAX request to dismiss welcome notice.
     * 
     * @since    1.0.0
     */
    public function ajax_dismiss_welcome_notice() {
        check_ajax_referer('fds-dismiss-welcome', 'nonce');
        update_option('fds_welcome_notice_dismissed', true);
        wp_send_json_success();
    }

    /**
     * Display the settings page content with improved layout.
     *
     * @since    1.0.0
     */
    public function display_settings_page() {
        // Check if FileBird is active
        if (!class_exists('FileBird\\Model\\Folder')) {
            echo '<div class="notice notice-error"><p>';
            echo '<span class="dashicons dashicons-warning" style="color: #d63638; margin-right: 10px;"></span>';
            _e('<strong>FileBird plugin is not active.</strong> Please install and activate <a href="https://wordpress.org/plugins/filebird/" target="_blank">FileBird</a> first.', 'filebird-dropbox-sync');
            echo '</p></div>';
            return;
        }
        
        // Display welcome notice
        $this->add_welcome_notice();
        
        // Get the active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        // Check if connected to Dropbox
        $is_connected = $this->is_connected_to_dropbox();
        
        // Display settings page
        include_once FDS_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }

    /**
     * Register plugin settings.
     *
     * @since    1.0.0
     */
    public function register_settings() {
        // Register settings for general tab
        register_setting('fds_general_settings', 'fds_sync_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ));
        
        register_setting('fds_general_settings', 'fds_conflict_resolution', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_conflict_resolution'),
            'default' => 'wordpress_wins',
        ));
        
        register_setting('fds_general_settings', 'fds_root_dropbox_folder', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_dropbox_path'),
            'default' => FDS_ROOT_DROPBOX_FOLDER,
        ));
        
        // Register settings for Dropbox API tab
        register_setting('fds_dropbox_settings', 'fds_dropbox_app_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => FDS_DROPBOX_APP_KEY,
        ));
        
        register_setting('fds_dropbox_settings', 'fds_dropbox_app_secret', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => FDS_DROPBOX_APP_SECRET,
        ));
        
        register_setting('fds_dropbox_settings', 'fds_dropbox_access_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        
        register_setting('fds_dropbox_settings', 'fds_dropbox_refresh_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        
        register_setting('fds_dropbox_settings', 'fds_dropbox_token_expiry', array(
            'type' => 'number',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ));
        
        // Register settings for advanced tab
        register_setting('fds_advanced_settings', 'fds_queue_batch_size', array(
            'type' => 'number',
            'sanitize_callback' => 'absint',
            'default' => 10,
        ));
        
        register_setting('fds_advanced_settings', 'fds_max_retries', array(
            'type' => 'number',
            'sanitize_callback' => 'absint',
            'default' => 3,
        ));
        
        register_setting('fds_advanced_settings', 'fds_log_level', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_log_level'),
            'default' => 'error',
        ));
        
        // Add settings sections
        add_settings_section(
            'fds_general_section',
            __('General Settings', 'filebird-dropbox-sync'),
            array($this, 'render_general_section'),
            'fds_general_settings'
        );
        
        add_settings_section(
            'fds_dropbox_section',
            __('Dropbox API Settings', 'filebird-dropbox-sync'),
            array($this, 'render_dropbox_section'),
            'fds_dropbox_settings'
        );
        
        add_settings_section(
            'fds_dropbox_webhook_section',
            __('Webhook Configuration', 'filebird-dropbox-sync'),
            array($this, 'render_dropbox_webhook_section'),
            'fds_dropbox_settings'
        );
        
        add_settings_section(
            'fds_advanced_section',
            __('Advanced Settings', 'filebird-dropbox-sync'),
            array($this, 'render_advanced_section'),
            'fds_advanced_settings'
        );
        
        // Add settings fields for general section
        add_settings_field(
            'fds_sync_enabled',
            __('Enable Synchronization', 'filebird-dropbox-sync'),
            array($this, 'render_sync_enabled_field'),
            'fds_general_settings',
            'fds_general_section'
        );
        
        add_settings_field(
            'fds_conflict_resolution',
            __('Conflict Resolution', 'filebird-dropbox-sync'),
            array($this, 'render_conflict_resolution_field'),
            'fds_general_settings',
            'fds_general_section'
        );
        
        add_settings_field(
            'fds_root_dropbox_folder',
            __('Root Dropbox Folder', 'filebird-dropbox-sync'),
            array($this, 'render_root_dropbox_folder_field'),
            'fds_general_settings',
            'fds_general_section'
        );
        
        // Add settings fields for Dropbox API section
        add_settings_field(
            'fds_dropbox_credentials',
            __('Dropbox API Credentials', 'filebird-dropbox-sync'),
            array($this, 'render_dropbox_credentials_field'),
            'fds_dropbox_settings',
            'fds_dropbox_section'
        );
        
        add_settings_field(
            'fds_dropbox_connection',
            __('Dropbox Connection', 'filebird-dropbox-sync'),
            array($this, 'render_dropbox_connection_field'),
            'fds_dropbox_settings',
            'fds_dropbox_section'
        );
        
        // Add webhook status field
        add_settings_field(
            'fds_webhook_status',
            __('Webhook Status & Setup', 'filebird-dropbox-sync'),
            array($this, 'render_webhook_status_field'),
            'fds_dropbox_settings',
            'fds_dropbox_webhook_section'
        );
        
        // Add settings fields for advanced section
        add_settings_field(
            'fds_queue_batch_size',
            __('Queue Batch Size', 'filebird-dropbox-sync'),
            array($this, 'render_queue_batch_size_field'),
            'fds_advanced_settings',
            'fds_advanced_section'
        );
        
        add_settings_field(
            'fds_max_retries',
            __('Maximum Retry Attempts', 'filebird-dropbox-sync'),
            array($this, 'render_max_retries_field'),
            'fds_advanced_settings',
            'fds_advanced_section'
        );
        
        add_settings_field(
            'fds_log_level',
            __('Log Level', 'filebird-dropbox-sync'),
            array($this, 'render_log_level_field'),
            'fds_advanced_settings',
            'fds_advanced_section'
        );
    }

    /**
     * Render the general section description.
     *
     * @since    1.0.0
     */
    public function render_general_section() {
        echo '<p>' . __('Configure the basic synchronization settings between FileBird and Dropbox.', 'filebird-dropbox-sync') . '</p>';
    }

    /**
     * Render the Dropbox API section description.
     *
     * @since    1.0.0
     */
    public function render_dropbox_section() {
        echo '<p>' . __('Connect to your Dropbox account and manage API credentials.', 'filebird-dropbox-sync') . '</p>';
    }

    /**
     * Render the Dropbox webhook section description.
     *
     * @since    1.0.0
     */
    public function render_dropbox_webhook_section() {
        // Only show if connected to Dropbox
        if ($this->is_connected_to_dropbox()) {
            echo '<p>' . __('Real-time synchronization requires setting up a webhook for your Dropbox app.', 'filebird-dropbox-sync') . '</p>';
        } else {
            echo '<div class="notice notice-info inline"><p>' . __('Please connect to Dropbox first before setting up webhooks.', 'filebird-dropbox-sync') . '</p></div>';
        }
    }

    /**
     * Render the advanced section description.
     *
     * @since    1.0.0
     */
    public function render_advanced_section() {
        echo '<p>' . __('Advanced settings for fine-tuning the synchronization process.', 'filebird-dropbox-sync') . '</p>';
    }

    /**
     * Render the webhook status field.
     *
     * @since    1.0.0
     */
    public function render_webhook_status_field() {
        // Only render if connected to Dropbox
        if ($this->is_connected_to_dropbox()) {
            echo $this->webhook->display_webhook_status();
        } else {
            echo '<div class="notice notice-warning inline"><p>' . __('Connect to Dropbox first to set up webhooks.', 'filebird-dropbox-sync') . '</p></div>';
        }
    }

    /**
     * Render the sync enabled field.
     *
     * @since    1.0.0
     */
    public function render_sync_enabled_field() {
        $sync_enabled = get_option('fds_sync_enabled', false);
        ?>
        <div class="fds-toggle-wrapper">
            <label class="fds-toggle" for="fds_sync_enabled">
                <input type="checkbox" id="fds_sync_enabled" name="fds_sync_enabled" value="1" <?php checked(1, $sync_enabled); ?>>
                <span class="fds-toggle-slider"></span>
            </label>
            <span class="fds-toggle-label">
                <?php _e('Enable two-way synchronization between FileBird and Dropbox', 'filebird-dropbox-sync'); ?>
            </span>
        </div>
        <p class="description">
            <?php _e('When enabled, changes in FileBird folders will be synced to Dropbox and vice versa in real-time.', 'filebird-dropbox-sync'); ?>
        </p>
        <?php
    }

    /**
     * Render the conflict resolution field.
     *
     * @since    1.0.0
     */
    public function render_conflict_resolution_field() {
        $conflict_resolution = get_option('fds_conflict_resolution', 'wordpress_wins');
        ?>
        <div class="fds-radio-group">
            <label class="fds-radio">
                <input type="radio" name="fds_conflict_resolution" value="wordpress_wins" <?php checked('wordpress_wins', $conflict_resolution); ?>>
                <span class="fds-radio-indicator"></span>
                <span class="fds-radio-label">
                    <strong><?php _e('WordPress Wins', 'filebird-dropbox-sync'); ?></strong>
                    <span class="fds-radio-description"><?php _e('If a file is modified in both places, keep the WordPress version', 'filebird-dropbox-sync'); ?></span>
                </span>
            </label>
            
            <label class="fds-radio">
                <input type="radio" name="fds_conflict_resolution" value="dropbox_wins" <?php checked('dropbox_wins', $conflict_resolution); ?>>
                <span class="fds-radio-indicator"></span>
                <span class="fds-radio-label">
                    <strong><?php _e('Dropbox Wins', 'filebird-dropbox-sync'); ?></strong>
                    <span class="fds-radio-description"><?php _e('If a file is modified in both places, keep the Dropbox version', 'filebird-dropbox-sync'); ?></span>
                </span>
            </label>
        </div>
        <p class="description">
            <?php _e('Choose which version to keep when a file is modified in both WordPress and Dropbox at the same time.', 'filebird-dropbox-sync'); ?>
        </p>
        <?php
    }

    /**
     * Render the root Dropbox folder field.
     *
     * @since    1.0.0
     */
    public function render_root_dropbox_folder_field() {
        $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
        ?>
        <div class="fds-input-wrapper">
            <div class="fds-input-group">
                <span class="fds-input-prefix">/</span>
                <input type="text" id="fds_root_dropbox_folder" name="fds_root_dropbox_folder" value="<?php echo esc_attr(ltrim($root_folder, '/')); ?>" class="regular-text">
            </div>
            <p class="description">
                <?php _e('The root folder in Dropbox to sync with FileBird. Default is /Website', 'filebird-dropbox-sync'); ?>
            </p>
            <div class="fds-path-preview">
                <?php _e('Your files will be synced to:', 'filebird-dropbox-sync'); ?> 
                <code>Dropbox<?php echo esc_html($root_folder); ?>/...</code>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Dropbox API credentials field.
     *
     * @since    1.0.0
     */
    public function render_dropbox_credentials_field() {
        $app_key = get_option('fds_dropbox_app_key', FDS_DROPBOX_APP_KEY);
        $app_secret = get_option('fds_dropbox_app_secret', FDS_DROPBOX_APP_SECRET);
        ?>
        <div class="fds-field-group">
            <label for="fds_dropbox_app_key"><?php _e('App Key', 'filebird-dropbox-sync'); ?></label>
            <input type="text" id="fds_dropbox_app_key" name="fds_dropbox_app_key" value="<?php echo esc_attr($app_key); ?>" class="regular-text" placeholder="<?php _e('Enter your Dropbox App Key', 'filebird-dropbox-sync'); ?>">
        </div>
        
        <div class="fds-field-group">
            <label for="fds_dropbox_app_secret"><?php _e('App Secret', 'filebird-dropbox-sync'); ?></label>
            <input type="password" id="fds_dropbox_app_secret" name="fds_dropbox_app_secret" value="<?php echo esc_attr($app_secret); ?>" class="regular-text" placeholder="<?php _e('Enter your Dropbox App Secret', 'filebird-dropbox-sync'); ?>">
        </div>
        
        <?php if (empty($app_key) || empty($app_secret)): ?>
        <div class="notice notice-info inline" style="margin: 10px 0;">
            <p>
                <span class="dashicons dashicons-info"></span>
                <?php _e('You need to create a Dropbox app in the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox Developer Console</a> to get your API credentials.', 'filebird-dropbox-sync'); ?>
            </p>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the Dropbox connection field.
     *
     * @since    1.0.0
     */
    public function render_dropbox_connection_field() {
        $is_connected = $this->is_connected_to_dropbox();
        $token_expiry = get_option('fds_dropbox_token_expiry', 0);
        $expires_at = $token_expiry ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $token_expiry) : '';
        
        if ($is_connected) {
            ?>
            <div class="fds-connection-status fds-connection-active">
                <div class="fds-connection-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="fds-connection-info">
                    <h3><?php _e('Connected to Dropbox', 'filebird-dropbox-sync'); ?></h3>
                    <p><?php printf(
                        __('Your WordPress site is connected to Dropbox. Token expires at %s.', 'filebird-dropbox-sync'),
                        '<strong>' . esc_html($expires_at) . '</strong>'
                    ); ?></p>
                </div>
                <div class="fds-connection-actions">
                    <button type="button" id="fds-disconnect-dropbox" class="button">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php _e('Disconnect', 'filebird-dropbox-sync'); ?>
                    </button>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="fds-connection-status fds-connection-inactive">
                <div class="fds-connection-icon">
                    <span class="dashicons dashicons-cloud"></span>
                </div>
                <div class="fds-connection-info">
                    <h3><?php _e('Not Connected to Dropbox', 'filebird-dropbox-sync'); ?></h3>
                    <p><?php _e('Click the button below to connect your WordPress site to Dropbox.', 'filebird-dropbox-sync'); ?></p>
                </div>
                <div class="fds-connection-actions">
                    <button type="button" id="fds-connect-dropbox" class="button button-primary">
                        <span class="dashicons dashicons-cloud-upload"></span>
                        <?php _e('Connect to Dropbox', 'filebird-dropbox-sync'); ?>
                    </button>
                </div>
            </div>
            <?php
        }
        
        echo '<div id="fds-connection-status" class="hidden"></div>';
    }

    /**
     * Render the queue batch size field.
     *
     * @since    1.0.0
     */
    public function render_queue_batch_size_field() {
        $batch_size = get_option('fds_queue_batch_size', 10);
        ?>
        <div class="fds-number-input-wrapper">
            <input type="number" id="fds_queue_batch_size" name="fds_queue_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="100" step="1" class="small-text">
            <div class="fds-range-slider">
                <input type="range" min="1" max="100" value="<?php echo esc_attr($batch_size); ?>" class="fds-range" id="fds_queue_batch_size_range">
            </div>
        </div>
        <p class="description">
            <?php _e('Number of tasks to process in each batch. Higher values may be faster but could use more system resources.', 'filebird-dropbox-sync'); ?>
        </p>
        <div class="fds-setting-info">
            <div class="fds-setting-recommended">
                <span class="dashicons dashicons-info"></span>
                <?php _e('Recommended: 10-50 tasks per batch for most servers', 'filebird-dropbox-sync'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the max retries field.
     *
     * @since    1.0.0
     */
    public function render_max_retries_field() {
        $max_retries = get_option('fds_max_retries', 3);
        ?>
        <div class="fds-number-input-wrapper">
            <input type="number" id="fds_max_retries" name="fds_max_retries" value="<?php echo esc_attr($max_retries); ?>" min="0" max="10" step="1" class="small-text">
            <div class="fds-range-slider">
                <input type="range" min="0" max="10" value="<?php echo esc_attr($max_retries); ?>" class="fds-range" id="fds_max_retries_range">
            </div>
        </div>
        <p class="description">
            <?php _e('Maximum number of retry attempts for failed sync operations before giving up.', 'filebird-dropbox-sync'); ?>
        </p>
        <div class="fds-setting-info">
            <div class="fds-setting-recommended">
                <span class="dashicons dashicons-info"></span>
                <?php _e('Recommended: 3-5 retries for reliable performance', 'filebird-dropbox-sync'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the log level field.
     *
     * @since    1.0.0
     */
    public function render_log_level_field() {
        $log_level = get_option('fds_log_level', 'error');
        ?>
        <div class="fds-select-wrapper">
            <select id="fds_log_level" name="fds_log_level">
                <option value="emergency" <?php selected('emergency', $log_level); ?>><?php _e('Emergency - System is unusable', 'filebird-dropbox-sync'); ?></option>
                <option value="alert" <?php selected('alert', $log_level); ?>><?php _e('Alert - Action must be taken immediately', 'filebird-dropbox-sync'); ?></option>
                <option value="critical" <?php selected('critical', $log_level); ?>><?php _e('Critical - Critical conditions', 'filebird-dropbox-sync'); ?></option>
                <option value="error" <?php selected('error', $log_level); ?>><?php _e('Error - Error conditions', 'filebird-dropbox-sync'); ?></option>
                <option value="warning" <?php selected('warning', $log_level); ?>><?php _e('Warning - Warning conditions', 'filebird-dropbox-sync'); ?></option>
                <option value="notice" <?php selected('notice', $log_level); ?>><?php _e('Notice - Normal but significant conditions', 'filebird-dropbox-sync'); ?></option>
                <option value="info" <?php selected('info', $log_level); ?>><?php _e('Info - Informational messages', 'filebird-dropbox-sync'); ?></option>
                <option value="debug" <?php selected('debug', $log_level); ?>><?php _e('Debug - Debug-level messages', 'filebird-dropbox-sync'); ?></option>
            </select>
        </div>
        <p class="description">
            <?php _e('Log level determines what type of events are recorded in the logs. More detailed levels (like Debug) will generate more logs.', 'filebird-dropbox-sync'); ?>
        </p>
        <div class="fds-setting-info">
            <div class="fds-setting-recommended">
                <span class="dashicons dashicons-info"></span>
                <?php _e('Recommended: Error for production, Debug for troubleshooting', 'filebird-dropbox-sync'); ?>
            </div>
            <div class="fds-setting-warning" style="margin-top: 10px;">
                <span class="dashicons dashicons-warning"></span>
                <?php _e('Warning: Debug level can generate large log files with 100,000+ files', 'filebird-dropbox-sync'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Add additional CSS for form elements
     */
    public function add_settings_css() {
        ?>
        <style>
        /* Toggle switch */
        .fds-toggle-wrapper {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .fds-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-right: 10px;
        }
        
        .fds-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .fds-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .fds-toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .fds-toggle input:checked + .fds-toggle-slider {
            background-color: #2271b1;
        }
        
        .fds-toggle input:checked + .fds-toggle-slider:before {
            transform: translateX(26px);
        }
        
        .fds-toggle-label {
            font-weight: 600;
        }
        
        /* Radio buttons */
        .fds-radio-group {
            margin-bottom: 10px;
        }
        
        .fds-radio {
            display: flex;
            align-items: flex-start;
            margin-bottom: 10px;
            cursor: pointer;
        }
        
        .fds-radio input {
            position: absolute;
            opacity: 0;
        }
        
        .fds-radio-indicator {
            position: relative;
            display: inline-block;
            width: 20px;
            height: 20px;
            margin-right: 10px;
            background: #fff;
            border: 2px solid #ccc;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 3px;
        }
        
        .fds-radio input:checked + .fds-radio-indicator {
            border-color: #2271b1;
        }
        
        .fds-radio input:checked + .fds-radio-indicator:after {
            content: '';
            position: absolute;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2271b1;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .fds-radio-label {
            display: flex;
            flex-direction: column;
        }
        
        .fds-radio-description {
            font-weight: normal;
            color: #646970;
            margin-top: 2px;
        }
        
        /* Number input with range slider */
        .fds-number-input-wrapper {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .fds-range-slider {
            margin-left: 15px;
            width: 200px;
        }
        
        .fds-range {
            width: 100%;
        }
        
        /* Connection status */
        .fds-connection-status {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .fds-connection-active {
            background-color: #f0fdf0;
            border: 1px solid #4ab866;
        }
        
        .fds-connection-inactive {
            background-color: #f0f0f1;
            border: 1px solid #c3c4c7;
        }
        
        .fds-connection-icon {
            font-size: 24px;
            margin-right: 15px;
        }
        
        .fds-connection-active .fds-connection-icon {
            color: #4ab866;
        }
        
        .fds-connection-inactive .fds-connection-icon {
            color: #646970;
        }
        
        .fds-connection-info {
            flex: 1;
        }
        
        .fds-connection-info h3 {
            margin: 0 0 5px 0;
        }
        
        .fds-connection-info p {
            margin: 0;
            color: #646970;
        }
        
        .fds-connection-actions {
            margin-left: 15px;
        }
        
        /* Input group */
        .fds-input-wrapper {
            margin-bottom: 10px;
        }
        
        .fds-input-group {
            display: flex;
            align-items: center;
        }
        
        .fds-input-prefix {
            padding: 0 8px;
            background: #f0f0f1;
            color: #3c434a;
            border: 1px solid #8c8f94;
            border-right: none;
            border-radius: 4px 0 0 4px;
            height: 30px;
            line-height: 30px;
        }
        
        .fds-input-group input {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        .fds-path-preview {
            margin-top: 10px;
            padding: 8px;
            background: #f0f0f1;
            border-radius: 4px;
            color: #3c434a;
        }
        
        /* Setting info */
        .fds-setting-info {
            margin-top: 10px;
        }
        
        .fds-setting-recommended {
            color: #2271b1;
            display: flex;
            align-items: center;
        }
        
        .fds-setting-warning {
            color: #dba617;
            display: flex;
            align-items: center;
        }
        
        .fds-setting-info .dashicons {
            margin-right: 5px;
        }
        
        /* Connect button with icon */
        #fds-connect-dropbox .dashicons,
        #fds-disconnect-dropbox .dashicons {
            margin: 4px 5px 0 -5px;
        }
        
        /* Webhook Status Card */
        .fds-webhook-status-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
        }
        
        .fds-webhook-status-card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: #2271b1;
            font-size: 16px;
        }
        
        .fds-status-item {
            display: flex;
            margin-bottom: 15px;
            align-items: center;
            padding: 5px 0;
        }
        
        .fds-status-label {
            font-weight: 600;
            min-width: 150px;
            color: #23282d;
        }
        
        .fds-status-value {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .fds-status-value code {
            background: #f0f0f1;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
            word-break: break-all;
            max-width: 100%;
        }
        
        .fds-status-indicator {
            display: flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .fds-status-indicator .dashicons {
            margin-right: 5px;
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        
        .fds-status-success {
            background-color: #f0fdf0;
            color: #4ab866;
        }
        
        .fds-status-warning {
            background-color: #fcf9e8;
            color: #dba617;
        }
        
        .fds-status-error {
            background-color: #fcf0f1;
            color: #d63638;
        }
        
        .fds-status-info {
            background-color: #f0f6fc;
            color: #2271b1;
        }
        
        .fds-webhook-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .fds-webhook-actions .button {
            display: flex;
            align-items: center;
        }
        
        .fds-webhook-actions .button .dashicons {
            margin: 4px 5px 0 -5px;
        }
        
        #fds-webhook-status-message {
            margin-top: 15px;
            padding: 10px 15px;
            border-radius: 4px;
            font-weight: 500;
        }
        
        /* Webhook Setup Guide */
        .fds-webhook-setup-guide {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
        }
        
        .fds-webhook-setup-guide h3 {
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: #2271b1;
            font-size: 16px;
        }
        
        .fds-setup-steps {
            margin: 0;
            padding: 0;
            list-style-type: none;
            counter-reset: step-counter;
        }
        
        .fds-setup-steps li {
            position: relative;
            padding: 15px 0 15px 45px;
            border-bottom: 1px solid #f0f0f1;
        }
        
        .fds-setup-steps li:last-child {
            border-bottom: none;
        }
        
        .fds-setup-steps li:before {
            content: counter(step-counter);
            counter-increment: step-counter;
            position: absolute;
            left: 0;
            top: 15px;
            width: 30px;
            height: 30px;
            background: #2271b1;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
        }
        
        .fds-setup-steps h4 {
            margin: 0 0 10px 0;
            color: #23282d;
            font-size: 15px;
        }
        
        .fds-setup-steps p {
            margin: 0 0 10px 0;
            color: #646970;
        }
        
        .fds-setup-steps p:last-child {
            margin-bottom: 0;
        }
        
        .fds-webhook-url-box {
            display: flex;
            align-items: center;
            background: #f0f0f1;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 10px 0;
            max-width: 100%;
            overflow: hidden;
        }
        
        .fds-webhook-url-box code {
            background: none;
            word-break: break-all;
            padding: 0;
            flex-grow: 1;
            font-size: 13px;
        }
        
        /* Add rotation animation */
        @keyframes rotation {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Sync range with number input
            $('.fds-range').on('input', function() {
                const targetId = $(this).attr('id').replace('_range', '');
                $('#' + targetId).val($(this).val());
            });
            
            // Sync number with range input
            $('input[type="number"]').on('input', function() {
                const rangeId = $(this).attr('id') + '_range';
                $('#' + rangeId).val($(this).val());
            });
            
            // Add tooltips to setting labels
            $('.fds-setting-info .fds-setting-recommended, .fds-setting-info .fds-setting-warning').each(function() {
                const text = $(this).text().trim();
                $(this).attr('title', text);
            });
        });
        </script>
        <?php
    }

    /**
     * Sanitize conflict resolution setting.
     *
     * @since    1.0.0
     * @param    string    $input    The input to sanitize.
     * @return   string              The sanitized input.
     */
    public function sanitize_conflict_resolution($input) {
        $valid_options = array('wordpress_wins', 'dropbox_wins');
        return in_array($input, $valid_options) ? $input : 'wordpress_wins';
    }

    /**
     * Sanitize Dropbox path.
     *
     * @since    1.0.0
     * @param    string    $input    The input to sanitize.
     * @return   string              The sanitized input.
     */
    public function sanitize_dropbox_path($input) {
        // Ensure path starts with a slash
        $path = '/' . ltrim(trim($input), '/');
        
        // Remove any trailing slash
        $path = rtrim($path, '/');
        
        return $path;
    }

    /**
     * Sanitize log level.
     *
     * @since    1.0.0
     * @param    string    $input    The input to sanitize.
     * @return   string              The sanitized input.
     */
    public function sanitize_log_level($input) {
        $valid_levels = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug');
        return in_array($input, $valid_levels) ? $input : 'error';
    }

    /**
     * Check if the plugin is connected to Dropbox.
     *
     * @since    1.0.0
     * @return   boolean   True if connected to Dropbox, false otherwise.
     */
    public function is_connected_to_dropbox() {
        $access_token = get_option('fds_dropbox_access_token', '');
        $token_expiry = get_option('fds_dropbox_token_expiry', 0);
        
        // Check if we have a token and it hasn't expired
        if (!empty($access_token) && $token_expiry > time()) {
            return true;
        }
        
        // If we have a refresh token, try to refresh the access token
        $refresh_token = get_option('fds_dropbox_refresh_token', '');
        if (!empty($refresh_token)) {
            $dropbox_api = new FDS_Dropbox_API($this);
            $refreshed = $dropbox_api->refresh_access_token();
            
            return $refreshed;
        }
        
        return false;
    }

    /**
     * Get plugin setting value
     *
     * @since    1.0.0
     * @param    string    $option_name    The option name to retrieve.
     * @param    mixed     $default        The default value to return if the option doesn't exist.
     * @return   mixed                     The option value or default.
     */
    public function get_setting($option_name, $default = false) {
        return get_option($option_name, $default);
    }
}