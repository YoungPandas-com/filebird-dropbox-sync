<div class="wrap">
    <h1><?php echo esc_html__('FileBird Dropbox Sync Settings', 'filebird-dropbox-sync'); ?></h1>
    
    <?php
    // Display connection status messages
    if (isset($_GET['connected']) && $_GET['connected'] == 1) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Successfully connected to Dropbox!', 'filebird-dropbox-sync'); ?></p>
        </div>
        <?php
    }
    
    if (isset($_GET['error'])) {
        $error = sanitize_text_field($_GET['error']);
        $message = '';
        
        switch ($error) {
            case 'csrf':
                $message = __('CSRF token validation failed. Please try again.', 'filebird-dropbox-sync');
                break;
            case 'code':
                $message = __('No authorization code received from Dropbox.', 'filebird-dropbox-sync');
                break;
            case 'request':
                $message = __('Error communicating with Dropbox API.', 'filebird-dropbox-sync');
                if (isset($_GET['message'])) {
                    $message .= ' ' . sanitize_text_field($_GET['message']);
                }
                break;
            case 'api':
                $message = __('Dropbox API error:', 'filebird-dropbox-sync');
                if (isset($_GET['message'])) {
                    $message .= ' ' . sanitize_text_field($_GET['message']);
                }
                break;
            case 'token':
                $message = __('Failed to get access token from Dropbox.', 'filebird-dropbox-sync');
                break;
            default:
                $message = __('An unknown error occurred.', 'filebird-dropbox-sync');
        }
        
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }
    ?>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=filebird-dropbox-sync-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=dropbox" class="nav-tab <?php echo $active_tab == 'dropbox' ? 'nav-tab-active' : ''; ?>"><?php _e('Dropbox Connection', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>"><?php _e('Advanced', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Logs', 'filebird-dropbox-sync'); ?></a>
    </h2>
    
    <div class="fds-settings-container">
        <?php if ($active_tab === 'general'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('fds_general_settings');
                do_settings_sections('fds_general_settings');
                
                // Add sync button if connected
                if ($is_connected) {
                    ?>
                    <div class="fds-sync-controls">
                        <h3><?php _e('Manual Synchronization', 'filebird-dropbox-sync'); ?></h3>
                        <p><?php _e('Trigger a full synchronization between WordPress and Dropbox.', 'filebird-dropbox-sync'); ?></p>
                        <button type="button" id="fds-manual-sync" class="button button-secondary"><?php _e('Start Full Sync', 'filebird-dropbox-sync'); ?></button>
                        <span id="fds-sync-status" class="fds-status-indicator"></span>
                        
                        <div id="fds-sync-progress" class="fds-progress-container" style="display: none;">
                            <div class="fds-progress-bar-wrapper">
                                <div class="fds-progress-bar"></div>
                            </div>
                            <div class="fds-progress-details">
                                <span class="fds-progress-status"><?php _e('Processing...', 'filebird-dropbox-sync'); ?></span>
                                <span class="fds-progress-counts"></span>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab === 'dropbox'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('fds_dropbox_settings');
                do_settings_sections('fds_dropbox_settings');
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab === 'advanced'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('fds_advanced_settings');
                do_settings_sections('fds_advanced_settings');
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab === 'logs'): ?>
            <div class="fds-logs-container">
                <h3><?php _e('Synchronization Logs', 'filebird-dropbox-sync'); ?></h3>
                <p><?php _e('Recent logs from the synchronization process.', 'filebird-dropbox-sync'); ?></p>
                
                <div class="fds-log-filters">
                    <label>
                        <?php _e('Log Level:', 'filebird-dropbox-sync'); ?>
                        <select id="fds-log-level-filter">
                            <option value="debug"><?php _e('Debug', 'filebird-dropbox-sync'); ?></option>
                            <option value="info"><?php _e('Info', 'filebird-dropbox-sync'); ?></option>
                            <option value="notice"><?php _e('Notice', 'filebird-dropbox-sync'); ?></option>
                            <option value="warning"><?php _e('Warning', 'filebird-dropbox-sync'); ?></option>
                            <option value="error" selected><?php _e('Error', 'filebird-dropbox-sync'); ?></option>
                            <option value="critical"><?php _e('Critical', 'filebird-dropbox-sync'); ?></option>
                            <option value="alert"><?php _e('Alert', 'filebird-dropbox-sync'); ?></option>
                            <option value="emergency"><?php _e('Emergency', 'filebird-dropbox-sync'); ?></option>
                        </select>
                    </label>
                    
                    <button type="button" id="fds-refresh-logs" class="button button-secondary"><?php _e('Refresh Logs', 'filebird-dropbox-sync'); ?></button>
                    <button type="button" id="fds-clear-logs" class="button button-secondary"><?php _e('Clear Logs', 'filebird-dropbox-sync'); ?></button>
                </div>
                
                <div class="fds-logs-table-wrapper">
                    <table class="widefat fds-logs-table">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'filebird-dropbox-sync'); ?></th>
                                <th><?php _e('Level', 'filebird-dropbox-sync'); ?></th>
                                <th><?php _e('Message', 'filebird-dropbox-sync'); ?></th>
                                <th><?php _e('Details', 'filebird-dropbox-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="fds-logs-tbody">
                            <tr>
                                <td colspan="4" class="fds-loading-logs"><?php _e('Loading logs...', 'filebird-dropbox-sync'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="fds-logs-pagination">
                    <button type="button" id="fds-logs-prev" class="button button-secondary" disabled><?php _e('Previous', 'filebird-dropbox-sync'); ?></button>
                    <span id="fds-logs-page-info"><?php _e('Page 1', 'filebird-dropbox-sync'); ?></span>
                    <button type="button" id="fds-logs-next" class="button button-secondary"><?php _e('Next', 'filebird-dropbox-sync'); ?></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>