<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines settings page, admin-specific hooks, and settings API integration.
 *
 * @since      1.0.0
 */
class FDS_Settings {

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles($hook) {
        if ('media_page_filebird-dropbox-sync-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_style('fds-admin', FDS_PLUGIN_URL . 'admin/css/fds-admin.css', array(), FDS_VERSION, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts($hook) {
        if ('media_page_filebird-dropbox-sync-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_script('fds-admin', FDS_PLUGIN_URL . 'admin/js/fds-admin.js', array('jquery'), FDS_VERSION, false);
        
        wp_localize_script('fds-admin', 'fds_admin_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fds-admin-nonce'),
            'strings' => array(
                'confirm_sync' => __('Are you sure you want to start a full synchronization? This may take a while for large libraries.', 'filebird-dropbox-sync'),
                'sync_started' => __('Synchronization started. This process will continue in the background.', 'filebird-dropbox-sync'),
                'connecting' => __('Connecting to Dropbox...', 'filebird-dropbox-sync'),
                'connected' => __('Successfully connected to Dropbox!', 'filebird-dropbox-sync'),
                'error' => __('An error occurred:', 'filebird-dropbox-sync'),
            )
        ));
    }

    /**
     * Add settings page to admin menu.
     *
     * @since    1.0.0
     */
    public function add_settings_page() {
        add_submenu_page(
            'options-general.php', // Place it under Settings instead of Media
            __('FileBird Dropbox Sync Settings', 'filebird-dropbox-sync'),
            __('FileBird Dropbox Sync', 'filebird-dropbox-sync'),
            'manage_fds_settings',
            'filebird-dropbox-sync-settings',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Display the settings page content.
     *
     * @since    1.0.0
     */
    public function display_settings_page() {
        // Check if FileBird is active
        if (!class_exists('FileBird\\Model\\Folder')) {
            echo '<div class="notice notice-error"><p>';
            _e('FileBird plugin is not active. Please install and activate FileBird first.', 'filebird-dropbox-sync');
            echo '</p></div>';
            return;
        }
        
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
     * Render the advanced section description.
     *
     * @since    1.0.0
     */
    public function render_advanced_section() {
        echo '<p>' . __('Advanced settings for fine-tuning the synchronization process.', 'filebird-dropbox-sync') . '</p>';
    }

    /**
     * Render the sync enabled field.
     *
     * @since    1.0.0
     */
    public function render_sync_enabled_field() {
        $sync_enabled = get_option('fds_sync_enabled', false);
        ?>
        <label for="fds_sync_enabled">
            <input type="checkbox" id="fds_sync_enabled" name="fds_sync_enabled" value="1" <?php checked(1, $sync_enabled); ?>>
            <?php _e('Enable two-way synchronization between FileBird and Dropbox', 'filebird-dropbox-sync'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, changes in FileBird folders will be synced to Dropbox and vice versa.', 'filebird-dropbox-sync'); ?>
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
        <select id="fds_conflict_resolution" name="fds_conflict_resolution">
            <option value="wordpress_wins" <?php selected('wordpress_wins', $conflict_resolution); ?>>
                <?php _e('WordPress Wins', 'filebird-dropbox-sync'); ?>
            </option>
            <option value="dropbox_wins" <?php selected('dropbox_wins', $conflict_resolution); ?>>
                <?php _e('Dropbox Wins', 'filebird-dropbox-sync'); ?>
            </option>
        </select>
        <p class="description">
            <?php _e('Choose which version to keep when a file is modified in both WordPress and Dropbox.', 'filebird-dropbox-sync'); ?>
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
        <input type="text" id="fds_root_dropbox_folder" name="fds_root_dropbox_folder" value="<?php echo esc_attr($root_folder); ?>" class="regular-text">
        <p class="description">
            <?php _e('The root folder in Dropbox to sync with FileBird. Default is /Website', 'filebird-dropbox-sync'); ?>
        </p>
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
            <input type="text" id="fds_dropbox_app_key" name="fds_dropbox_app_key" value="<?php echo esc_attr($app_key); ?>" class="regular-text">
        </div>
        
        <div class="fds-field-group">
            <label for="fds_dropbox_app_secret"><?php _e('App Secret', 'filebird-dropbox-sync'); ?></label>
            <input type="password" id="fds_dropbox_app_secret" name="fds_dropbox_app_secret" value="<?php echo esc_attr($app_secret); ?>" class="regular-text">
        </div>
        
        <p class="description">
            <?php _e('You need to create a Dropbox app in the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox Developer Console</a>.', 'filebird-dropbox-sync'); ?>
            <?php _e('Make sure to set the OAuth 2 redirect URI to:', 'filebird-dropbox-sync'); ?>
            <code><?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=fds_oauth_finish</code>
        </p>
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
            echo '<div class="notice notice-success inline"><p>';
            printf(
                __('Connected to Dropbox. Token expires at %s.', 'filebird-dropbox-sync'),
                '<strong>' . esc_html($expires_at) . '</strong>'
            );
            echo '</p></div>';
            
            echo '<button type="button" id="fds-disconnect-dropbox" class="button">';
            _e('Disconnect from Dropbox', 'filebird-dropbox-sync');
            echo '</button>';
            
            // Add webhook information
            $webhook_url = get_rest_url(null, 'fds/v1/webhook');
            echo '<div class="fds-webhook-info" style="margin-top: 25px; padding: 20px; background: #f9f9f9; border-radius: 5px; border-left: 5px solid #2271b1;">';
            echo '<h4 style="margin-top:0; color: #2271b1; font-size: 16px;">' . __('Webhook Setup Instructions', 'filebird-dropbox-sync') . '</h4>';
            echo '<p style="margin-bottom: 15px;">' . __('To enable real-time synchronization, you need to add the following webhook URL to your Dropbox App:', 'filebird-dropbox-sync') . '</p>';
            echo '<div style="background: #fff; padding: 12px; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 15px;">';
            echo '<code style="user-select: all; font-size: 14px;">' . esc_url($webhook_url) . '</code>';
            echo '<button type="button" class="button button-small copy-webhook-url" style="margin-left: 10px; vertical-align: middle;" data-clipboard-text="' . esc_url($webhook_url) . '">' . __('Copy', 'filebird-dropbox-sync') . '</button>';
            echo '</div>';
            echo '<div style="background: #f0f6fc; padding: 15px; border-radius: 4px; margin-bottom: 15px;">';
            echo '<h5 style="margin-top: 0; margin-bottom: 10px; color: #2271b1;">' . __('How to Add Webhook in Dropbox App Console:', 'filebird-dropbox-sync') . '</h5>';
            echo '<ol style="margin: 0; padding-left: 20px;">';
            echo '<li style="margin-bottom: 8px;">' . __('Go to <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox Developer Console</a>', 'filebird-dropbox-sync') . '</li>';
            echo '<li style="margin-bottom: 8px;">' . __('Select your app', 'filebird-dropbox-sync') . '</li>';
            echo '<li style="margin-bottom: 8px;">' . __('Navigate to the "Webhooks" section', 'filebird-dropbox-sync') . '</li>';
            echo '<li style="margin-bottom: 8px;">' . __('Enter the webhook URL shown above', 'filebird-dropbox-sync') . '</li>';
            echo '<li style="margin-bottom: 0;">' . __('Click "Add" to save your webhook', 'filebird-dropbox-sync') . '</li>';
            echo '</ol>';
            echo '</div>';
            echo '<p style="margin-bottom: 0; font-style: italic; color: #666;">' . __('Note: If you receive a "challenge" error, ensure your server is properly configured to handle webhook requests.', 'filebird-dropbox-sync') . '</p>';
            echo '</div>';
        } else {
            echo '<button type="button" id="fds-connect-dropbox" class="button button-primary">';
            _e('Connect to Dropbox', 'filebird-dropbox-sync');
            echo '</button>';
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
        <input type="number" id="fds_queue_batch_size" name="fds_queue_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" step="1">
        <p class="description">
            <?php _e('Number of tasks to process in each batch. Higher values may be faster but could use more resources.', 'filebird-dropbox-sync'); ?>
        </p>
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
        <input type="number" id="fds_max_retries" name="fds_max_retries" value="<?php echo esc_attr($max_retries); ?>" min="0" max="10" step="1">
        <p class="description">
            <?php _e('Maximum number of retry attempts for failed sync operations before giving up.', 'filebird-dropbox-sync'); ?>
        </p>
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
        <select id="fds_log_level" name="fds_log_level">
            <option value="emergency" <?php selected('emergency', $log_level); ?>><?php _e('Emergency', 'filebird-dropbox-sync'); ?></option>
            <option value="alert" <?php selected('alert', $log_level); ?>><?php _e('Alert', 'filebird-dropbox-sync'); ?></option>
            <option value="critical" <?php selected('critical', $log_level); ?>><?php _e('Critical', 'filebird-dropbox-sync'); ?></option>
            <option value="error" <?php selected('error', $log_level); ?>><?php _e('Error', 'filebird-dropbox-sync'); ?></option>
            <option value="warning" <?php selected('warning', $log_level); ?>><?php _e('Warning', 'filebird-dropbox-sync'); ?></option>
            <option value="notice" <?php selected('notice', $log_level); ?>><?php _e('Notice', 'filebird-dropbox-sync'); ?></option>
            <option value="info" <?php selected('info', $log_level); ?>><?php _e('Info', 'filebird-dropbox-sync'); ?></option>
            <option value="debug" <?php selected('debug', $log_level); ?>><?php _e('Debug', 'filebird-dropbox-sync'); ?></option>
        </select>
        <p class="description">
            <?php _e('Log level determines what type of events are recorded in the logs.', 'filebird-dropbox-sync'); ?>
        </p>
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