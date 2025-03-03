<div class="wrap">
    <h1><?php echo esc_html__('FileBird Dropbox Sync', 'filebird-dropbox-sync'); ?></h1>
    
    <?php
    // Display connection status messages with improved styling
    if (isset($_GET['connected']) && $_GET['connected'] == 1) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php _e('Success!', 'filebird-dropbox-sync'); ?></strong> <?php _e('Your WordPress site is now connected to Dropbox!', 'filebird-dropbox-sync'); ?></p>
        </div>
        <?php
    }
    
    if (isset($_GET['error'])) {
        $error = sanitize_text_field($_GET['error']);
        $message = '';
        
        switch ($error) {
            case 'csrf':
                $message = __('Security token validation failed. Please try connecting again.', 'filebird-dropbox-sync');
                break;
            case 'code':
                $message = __('No authorization code received from Dropbox. Please make sure you authorize the application.', 'filebird-dropbox-sync');
                break;
            case 'request':
                $message = __('Error communicating with Dropbox API. Please try again later.', 'filebird-dropbox-sync');
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
                $message = __('Failed to get access token from Dropbox. Please check your App Key and Secret.', 'filebird-dropbox-sync');
                break;
            default:
                $message = __('An unknown error occurred. Please try again.', 'filebird-dropbox-sync');
        }
        
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong><?php _e('Error:', 'filebird-dropbox-sync'); ?></strong> <?php echo esc_html($message); ?></p>
        </div>
        <?php
    }
    ?>
    
    <div class="fds-header-bar" style="margin: 15px 0; padding: 15px; background: #fff; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h2 style="margin: 0; padding: 0; color: #2271b1;"><?php _e('Sync FileBird folders with Dropbox', 'filebird-dropbox-sync'); ?></h2>
                <p style="margin: 5px 0 0 0;"><?php _e('Connect your WordPress media library to Dropbox for seamless, two-way synchronization.', 'filebird-dropbox-sync'); ?></p>
            </div>
            <div>
                <?php if ($is_connected): ?>
                    <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 24px;"></span> 
                    <span style="color: #46b450; font-weight: 500;"><?php _e('Connected to Dropbox', 'filebird-dropbox-sync'); ?></span>
                <?php else: ?>
                    <span class="dashicons dashicons-warning" style="color: #f56e28; font-size: 24px;"></span>
                    <span style="color: #f56e28; font-weight: 500;"><?php _e('Not connected to Dropbox', 'filebird-dropbox-sync'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=filebird-dropbox-sync-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=dropbox" class="nav-tab <?php echo $active_tab == 'dropbox' ? 'nav-tab-active' : ''; ?>"><?php _e('Dropbox Connection', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>"><?php _e('Advanced', 'filebird-dropbox-sync'); ?></a>
        <a href="?page=filebird-dropbox-sync-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Logs', 'filebird-dropbox-sync'); ?></a>
    </h2>
    
    <div class="fds-settings-container">
        <?php if ($active_tab === 'general'): ?>
            <form method="post" action="options.php" class="fds-form">
                <div class="fds-settings-section">
                    <?php
                    settings_fields('fds_general_settings');
                    do_settings_sections('fds_general_settings');
                    
                    // Add sync button if connected
                    if ($is_connected) {
                        ?>
                        <div class="fds-sync-controls">
                            <h3><?php _e('Manual Synchronization', 'filebird-dropbox-sync'); ?></h3>
                            <p><?php _e('Trigger a full synchronization between your WordPress media library and Dropbox.', 'filebird-dropbox-sync'); ?></p>
                            
                            <div class="fds-action-row" style="margin-bottom: 15px;">
                                <button type="button" id="fds-manual-sync" class="button button-primary"><?php _e('Start Full Sync', 'filebird-dropbox-sync'); ?></button>
                                <span id="fds-sync-status" class="fds-status-indicator"></span>
                            </div>
                            
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
                        
                        <div class="fds-sync-dashboard">
                            <h3><?php _e('Sync Status Dashboard', 'filebird-dropbox-sync'); ?></h3>
                            
                            <div class="fds-stats-grid">
                                <div class="fds-stat-card fds-stat-blue">
                                    <h4><?php _e('Total Files', 'filebird-dropbox-sync'); ?></h4>
                                    <p class="fds-stat-value" id="fds-total-files">-</p>
                                </div>
                                
                                <div class="fds-stat-card fds-stat-green">
                                    <h4><?php _e('Synced Files', 'filebird-dropbox-sync'); ?></h4>
                                    <p class="fds-stat-value" id="fds-synced-files">-</p>
                                </div>
                                
                                <div class="fds-stat-card fds-stat-yellow">
                                    <h4><?php _e('Pending Tasks', 'filebird-dropbox-sync'); ?></h4>
                                    <p class="fds-stat-value" id="fds-pending-tasks">-</p>
                                </div>
                                
                                <div class="fds-stat-card fds-stat-red">
                                    <h4><?php _e('Failed Tasks', 'filebird-dropbox-sync'); ?></h4>
                                    <p class="fds-stat-value" id="fds-failed-tasks">-</p>
                                </div>
                            </div>
                            
                            <div class="fds-action-buttons">
                                <button type="button" id="fds-refresh-stats" class="button button-secondary">
                                    <span class="dashicons dashicons-update" style="margin: 4px 5px 0 -5px;"></span>
                                    <?php _e('Refresh Stats', 'filebird-dropbox-sync'); ?>
                                </button>
                                
                                <button type="button" id="fds-force-process" class="button button-secondary">
                                    <span class="dashicons dashicons-controls-play" style="margin: 4px 5px 0 -5px;"></span>
                                    <?php _e('Process Queue Now', 'filebird-dropbox-sync'); ?>
                                </button>
                                
                                <button type="button" id="fds-retry-failed" class="button button-secondary">
                                    <span class="dashicons dashicons-image-rotate" style="margin: 4px 5px 0 -5px;"></span>
                                    <?php _e('Retry Failed Tasks', 'filebird-dropbox-sync'); ?>
                                </button>
                            </div>
                            
                            <div id="fds-action-status" style="display: none;"></div>
                        </div>
                        <?php
                    } else {
                        // Show a notice about needing to connect first
                        ?>
                        <div class="notice notice-info inline" style="margin: 20px 0;">
                            <p>
                                <span class="dashicons dashicons-info" style="color: #2271b1; margin-right: 10px;"></span>
                                <?php _e('Please connect to Dropbox in the <a href="?page=filebird-dropbox-sync-settings&tab=dropbox">Dropbox Connection</a> tab before you can start synchronizing your files.', 'filebird-dropbox-sync'); ?>
                            </p>
                        </div>
                        <?php
                    }
                    
                    submit_button();
                    ?>
                </div>
            </form>
        <?php elseif ($active_tab === 'dropbox'): ?>
            <div class="fds-settings-section">
                <?php if (!$is_connected): ?>
                    <div class="fds-connection-guide">
                        <h3><?php _e('How to Connect to Dropbox', 'filebird-dropbox-sync'); ?></h3>
                        <ol class="fds-step-list">
                            <li>
                                <strong><?php _e('Create a Dropbox App', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('Go to the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox App Console</a> and click "Create app".', 'filebird-dropbox-sync'); ?></p>
                                <p><?php _e('Choose "Scoped access", then "Full Dropbox" access, and give your app a name.', 'filebird-dropbox-sync'); ?></p>
                            </li>
                            <li>
                                <strong><?php _e('Configure Permissions', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('In your app settings, go to the "Permissions" tab and select the following permissions:', 'filebird-dropbox-sync'); ?></p>
                                <ul class="fds-permission-list">
                                    <li>files.metadata.write</li>
                                    <li>files.metadata.read</li>
                                    <li>files.content.write</li>
                                    <li>files.content.read</li>
                                </ul>
                                <p><?php _e('Click "Submit" to save the permissions.', 'filebird-dropbox-sync'); ?></p>
                            </li>
                            <li>
                                <strong><?php _e('Add Redirect URI', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('In your app settings, go to the "OAuth 2" tab and add the following Redirect URI:', 'filebird-dropbox-sync'); ?></p>
                                <div class="fds-code-box">
                                    <code><?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=fds_oauth_finish</code>
                                    <button type="button" class="button button-small copy-webhook-url" data-clipboard-text="<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=fds_oauth_finish">
                                        <?php _e('Copy', 'filebird-dropbox-sync'); ?>
                                    </button>
                                </div>
                            </li>
                            <li>
                                <strong><?php _e('Get App Credentials', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('From your app settings, copy the "App key" and "App secret".', 'filebird-dropbox-sync'); ?></p>
                            </li>
                            <li>
                                <strong><?php _e('Enter Credentials Below', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('Enter the App key and App secret in the form below and click "Save Changes".', 'filebird-dropbox-sync'); ?></p>
                            </li>
                            <li>
                                <strong><?php _e('Connect to Dropbox', 'filebird-dropbox-sync'); ?></strong>
                                <p><?php _e('After saving your credentials, click the "Connect to Dropbox" button.', 'filebird-dropbox-sync'); ?></p>
                            </li>
                        </ol>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="options.php" class="fds-form">
                    <?php
                    settings_fields('fds_dropbox_settings');
                    do_settings_sections('fds_dropbox_settings');
                    submit_button();
                    ?>
                </form>
                
                <?php if ($is_connected): ?>
                    <div class="fds-webhook-info">
                        <h3><?php _e('Webhook Setup Instructions', 'filebird-dropbox-sync'); ?></h3>
                        <p><?php _e('To enable real-time synchronization when files change in Dropbox, you need to add the following webhook URL to your Dropbox App:', 'filebird-dropbox-sync'); ?></p>
                        
                        <div class="fds-code-box">
                            <code><?php echo esc_url(get_rest_url(null, 'fds/v1/webhook')); ?></code>
                            <button type="button" class="button button-small copy-webhook-url" data-clipboard-text="<?php echo esc_url(get_rest_url(null, 'fds/v1/webhook')); ?>">
                                <?php _e('Copy', 'filebird-dropbox-sync'); ?>
                            </button>
                        </div>
                        
                        <div class="fds-instruction-box">
                            <h4><?php _e('How to Add Webhook in Dropbox App Console:', 'filebird-dropbox-sync'); ?></h4>
                            <ol>
                                <li><?php _e('Go to <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox Developer Console</a>', 'filebird-dropbox-sync'); ?></li>
                                <li><?php _e('Select your app', 'filebird-dropbox-sync'); ?></li>
                                <li><?php _e('Navigate to the "Webhooks" section', 'filebird-dropbox-sync'); ?></li>
                                <li><?php _e('Enter the webhook URL shown above', 'filebird-dropbox-sync'); ?></li>
                                <li><?php _e('Click "Add" to save your webhook', 'filebird-dropbox-sync'); ?></li>
                            </ol>
                        </div>
                        
                        <p class="fds-note"><?php _e('Note: If you receive a "challenge" error, ensure your server is properly configured to handle webhook requests.', 'filebird-dropbox-sync'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($active_tab === 'advanced'): ?>
            <div class="fds-settings-section">
                <form method="post" action="options.php" class="fds-form">
                    <?php
                    settings_fields('fds_advanced_settings');
                    do_settings_sections('fds_advanced_settings');
                    submit_button();
                    ?>
                </form>
            </div>
        <?php elseif ($active_tab === 'logs'): ?>
            <div class="fds-settings-section">
                <div class="fds-logs-container">
                    <div class="fds-section-header">
                        <h3><?php _e('Synchronization Logs', 'filebird-dropbox-sync'); ?></h3>
                        <p><?php _e('Review recent logs to troubleshoot synchronization issues.', 'filebird-dropbox-sync'); ?></p>
                    </div>
                    
                    <div class="fds-log-filters">
                        <div class="fds-filter-group">
                            <label for="fds-log-level-filter">
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
                        </div>
                        
                        <div class="fds-button-group">
                            <button type="button" id="fds-refresh-logs" class="button button-secondary">
                                <span class="dashicons dashicons-update" style="margin: 4px 5px 0 -5px;"></span>
                                <?php _e('Refresh Logs', 'filebird-dropbox-sync'); ?>
                            </button>
                            <button type="button" id="fds-clear-logs" class="button button-secondary">
                                <span class="dashicons dashicons-trash" style="margin: 4px 5px 0 -5px;"></span>
                                <?php _e('Clear Logs', 'filebird-dropbox-sync'); ?>
                            </button>
                        </div>
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
            </div>
        <?php endif; ?>
    </div>
</div>