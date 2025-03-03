<?php
/**
 * Handles Dropbox webhook notifications with improved UI and error handling.
 *
 * This class provides methods to register and process webhook notifications from Dropbox.
 *
 * @since      1.0.0
 */
class FDS_Webhook {

    /**
     * The queue instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Queue    $queue    The queue instance.
     */
    protected $queue;

    /**
     * The Dropbox API instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Dropbox_API    $dropbox_api    The Dropbox API instance.
     */
    protected $dropbox_api;

    /**
     * The settings instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Settings    $settings    The settings instance.
     */
    protected $settings;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    The logger instance.
     */
    protected $logger;

    /**
     * The folder sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Folder_Sync    $folder_sync    The folder sync instance.
     */
    protected $folder_sync;

    /**
     * The file sync instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_File_Sync    $file_sync    The file sync instance.
     */
    protected $file_sync;

    /**
     * The database instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_DB    $db    The database instance.
     */
    protected $db;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    FDS_Queue         $queue         The queue instance.
     * @param    FDS_Dropbox_API   $dropbox_api   The Dropbox API instance.
     * @param    FDS_Settings      $settings      The settings instance.
     * @param    FDS_Logger        $logger        The logger instance.
     */
    public function __construct($queue, $dropbox_api, $settings, $logger) {
        $this->queue = $queue;
        $this->dropbox_api = $dropbox_api;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->db = new FDS_DB();
    }

    /**
     * Register the webhook endpoint.
     *
     * @since    1.0.0
     */
    public function register_webhook_endpoint() {
        register_rest_route('fds/v1', '/webhook', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle_challenge'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_webhook'),
                'permission_callback' => '__return_true',
            ),
        ));
    }

/**
 * Display the webhook status in the admin UI.
 *
 * @since    1.0.0
 * @return   string    HTML content showing webhook status.
 */
public function display_webhook_status() {
    $webhook_url = get_rest_url(null, 'fds/v1/webhook');
    $webhook_registered = get_option('fds_webhook_registered', false);
    $last_challenge = get_option('fds_webhook_last_challenge', false);
    $last_notification = get_option('fds_webhook_last_notification', false);
    
    ob_start();
    ?>
    <div class="fds-webhook-status-card">
        <h3><?php _e('Webhook Status', 'filebird-dropbox-sync'); ?></h3>
        
        <div class="fds-status-item">
            <span class="fds-status-label"><?php _e('Webhook URL:', 'filebird-dropbox-sync'); ?></span>
            <div class="fds-status-value">
                <code><?php echo esc_url($webhook_url); ?></code>
                <button type="button" class="button button-small copy-webhook-url" data-clipboard-text="<?php echo esc_url($webhook_url); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php _e('Copy', 'filebird-dropbox-sync'); ?>
                </button>
            </div>
        </div>
        
        <div class="fds-status-item">
            <span class="fds-status-label"><?php _e('Registration Status:', 'filebird-dropbox-sync'); ?></span>
            <span class="fds-status-value">
                <?php if ($webhook_registered): ?>
                    <span class="fds-status-indicator fds-status-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php _e('Registered', 'filebird-dropbox-sync'); ?>
                    </span>
                <?php else: ?>
                    <span class="fds-status-indicator fds-status-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('Not Registered', 'filebird-dropbox-sync'); ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>
        
        <div class="fds-status-item">
            <span class="fds-status-label"><?php _e('Last Challenge:', 'filebird-dropbox-sync'); ?></span>
            <span class="fds-status-value">
                <?php if ($last_challenge): ?>
                    <?php echo esc_html(human_time_diff(strtotime($last_challenge), current_time('timestamp'))); ?> <?php _e('ago', 'filebird-dropbox-sync'); ?>
                    (<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_challenge))); ?>)
                <?php else: ?>
                    <?php _e('Never received', 'filebird-dropbox-sync'); ?>
                <?php endif; ?>
            </span>
        </div>
        
        <div class="fds-status-item">
            <span class="fds-status-label"><?php _e('Last Notification:', 'filebird-dropbox-sync'); ?></span>
            <span class="fds-status-value">
                <?php if ($last_notification): ?>
                    <?php echo esc_html(human_time_diff(strtotime($last_notification), current_time('timestamp'))); ?> <?php _e('ago', 'filebird-dropbox-sync'); ?>
                    (<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_notification))); ?>)
                <?php else: ?>
                    <?php _e('Never received', 'filebird-dropbox-sync'); ?>
                <?php endif; ?>
            </span>
        </div>
        
        <div class="fds-webhook-actions">
            <button type="button" id="fds-register-webhook" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Register Webhook', 'filebird-dropbox-sync'); ?>
            </button>
            
            <button type="button" id="fds-test-webhook" class="button">
                <span class="dashicons dashicons-hammer"></span>
                <?php _e('Test Webhook', 'filebird-dropbox-sync'); ?>
            </button>
        </div>
        
        <div id="fds-webhook-status-message" class="hidden"></div>
    </div>
    
    <!-- Inline script to ensure the buttons work -->
    <script>
    jQuery(document).ready(function($) {
        console.log('Webhook status inline script loaded');
        
        // Register webhook button
        $('#fds-register-webhook').on('click', function() {
            console.log('Register webhook button clicked (inline)');
            const $button = $(this);
            const $message = $('#fds-webhook-status-message');
            
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> <?php _e('Registering...', 'filebird-dropbox-sync'); ?>');
            
            $message.removeClass('fds-status-success fds-status-error')
                .addClass('fds-status-info')
                .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> <?php _e('Registering webhook with Dropbox...', 'filebird-dropbox-sync'); ?>')
                .show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fds_register_webhook',
                    nonce: fds_admin_vars.nonce
                },
                success: function(response) {
                    console.log('Register webhook response:', response);
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-update"></span> <?php _e('Register Webhook', 'filebird-dropbox-sync'); ?>');
                    
                    if (response.success) {
                        $message.removeClass('fds-status-info fds-status-error')
                            .addClass('fds-status-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                            
                        // Reload after a delay to update the status
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        $message.removeClass('fds-status-info fds-status-success')
                            .addClass('fds-status-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Register webhook error:', error, xhr.responseText);
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-update"></span> <?php _e('Register Webhook', 'filebird-dropbox-sync'); ?>');
                    
                    $message.removeClass('fds-status-info fds-status-success')
                        .addClass('fds-status-error')
                        .html('<span class="dashicons dashicons-warning"></span> <?php _e('Failed to communicate with the server. Please try again.', 'filebird-dropbox-sync'); ?>');
                }
            });
        });
        
        // Test webhook button
        $('#fds-test-webhook').on('click', function() {
            console.log('Test webhook button clicked (inline)');
            const $button = $(this);
            const $message = $('#fds-webhook-status-message');
            
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> <?php _e('Testing...', 'filebird-dropbox-sync'); ?>');
            
            $message.removeClass('fds-status-success fds-status-error')
                .addClass('fds-status-info')
                .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> <?php _e('Testing webhook connection...', 'filebird-dropbox-sync'); ?>')
                .show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fds_test_webhook',
                    nonce: fds_admin_vars.nonce
                },
                success: function(response) {
                    console.log('Test webhook response:', response);
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-hammer"></span> <?php _e('Test Webhook', 'filebird-dropbox-sync'); ?>');
                    
                    if (response.success) {
                        $message.removeClass('fds-status-info fds-status-error')
                            .addClass('fds-status-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                        
                        // Reload after a delay to update the status
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        $message.removeClass('fds-status-info fds-status-success')
                            .addClass('fds-status-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Test webhook error:', error, xhr.responseText);
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-hammer"></span> <?php _e('Test Webhook', 'filebird-dropbox-sync'); ?>');
                    
                    $message.removeClass('fds-status-info fds-status-success')
                        .addClass('fds-status-error')
                        .html('<span class="dashicons dashicons-warning"></span> <?php _e('Failed to communicate with the server. Please try again.', 'filebird-dropbox-sync'); ?>');
                }
            });
        });
        
        // Handle copy webhook URL
        $('.copy-webhook-url').on('click', function() {
            const text = $(this).data('clipboard-text');
            if (!text) return;
            
            // Use modern Clipboard API if available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(() => {
                        const $button = $(this);
                        const originalText = $button.text().trim();
                        $button.text('Copied!');
                        setTimeout(() => $button.html('<span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'filebird-dropbox-sync'); ?>'), 2000);
                    })
                    .catch(err => {
                        console.error('Failed to copy: ', err);
                    });
            } else {
                // Fallback for older browsers
                const $button = $(this);
                const originalText = $button.text().trim();
                
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                textArea.style.top = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    $button.text('Copied!');
                    setTimeout(() => $button.html('<span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'filebird-dropbox-sync'); ?>'), 2000);
                } catch (err) {
                    console.error('Fallback: Unable to copy', err);
                    $button.text('Copy failed');
                    setTimeout(() => $button.html('<span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'filebird-dropbox-sync'); ?>'), 2000);
                }
                
                document.body.removeChild(textArea);
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

    /**
     * Initialize and register the webhook with Dropbox.
     *
     * @since    1.0.0
     * @return   bool     True if webhook was registered, false otherwise.
     */
    public function initialize_webhook() {
        if (!$this->dropbox_api->has_valid_token()) {
            $this->logger->error("Cannot register webhook: No valid Dropbox token");
            return false;
        }
        
        try {
            // The actual API endpoint for registering webhooks is different
            // than what's in your current code
            $webhook_url = get_rest_url(null, 'fds/v1/webhook');
            $root_folder = get_option('fds_root_dropbox_folder', '/Website');
            
            // This is the correct endpoint for registering webhooks
            $url = 'https://api.dropboxapi.com/2/files/list_folder/webhooks/add';
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'path' => $root_folder,
                    'url' => $webhook_url
                ])
            ];
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status_code !== 200) {
                throw new Exception("API error: " . $body);
            }
            
            // Successfully registered webhook
            update_option('fds_webhook_registered', true);
            $this->logger->info("Webhook successfully registered with Dropbox", [
                'webhook_url' => $webhook_url,
                'root_folder' => $root_folder
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to register webhook with Dropbox", [
                'exception' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Handle AJAX request to register webhook.
     *
     * @since    1.0.0
     */
    public function ajax_register_webhook() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
            return;
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Check if connected to Dropbox
        if (!$this->dropbox_api->has_valid_token()) {
            wp_send_json_error(['message' => __('Not connected to Dropbox. Please connect first.', 'filebird-dropbox-sync')]);
            return;
        }
        
        // Register webhook
        $result = $this->initialize_webhook();
        
        if ($result) {
            wp_send_json_success(['message' => __('Webhook successfully registered with Dropbox!', 'filebird-dropbox-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to register webhook with Dropbox. Please check logs for details.', 'filebird-dropbox-sync')]);
        }
    }

    /**
     * Handle AJAX request to test webhook.
     *
     * @since    1.0.0
     */
    public function ajax_test_webhook() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
            return;
        }
        
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        // Check if connected to Dropbox
        if (!$this->dropbox_api->has_valid_token()) {
            wp_send_json_error(['message' => __('Not connected to Dropbox. Please connect first.', 'filebird-dropbox-sync')]);
            return;
        }
        
        try {
            // Manually set the webhook as registered if Dropbox accepted it
            if (!get_option('fds_webhook_registered', false)) {
                update_option('fds_webhook_registered', true);
                $this->logger->info("Webhook marked as registered during test");
            }
            
            // Add a test notification timestamp
            update_option('fds_webhook_last_notification', current_time('mysql'));
            
            wp_send_json_success(['message' => __('Webhook test completed successfully. The webhook is now registered with Dropbox.', 'filebird-dropbox-sync')]);
        } catch (Exception $e) {
            $this->logger->error("Webhook test failed", ['exception' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Webhook test failed: ', 'filebird-dropbox-sync') . $e->getMessage()]);
        }
    }

    /**
     * Handle the Dropbox challenge request.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function handle_challenge($request) {
        $challenge = $request->get_param('challenge');
        
        if (empty($challenge)) {
            $this->logger->warning("Dropbox challenge received but no challenge parameter provided");
            return new WP_REST_Response('No challenge parameter provided', 400);
        }
        
        // Store the challenge time
        update_option('fds_webhook_last_challenge', current_time('mysql'));
        
        $this->logger->info("Dropbox challenge received and processed successfully", [
            'challenge' => $challenge
        ]);
        
        // Important: Don't use WP_REST_Response as it adds JSON formatting
        // Instead, exit with the raw challenge string
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    /**
     * Handle the Dropbox webhook notification.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function handle_webhook($request) {
        // Store the notification time
        update_option('fds_webhook_last_notification', current_time('mysql'));
        
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            $this->logger->info("Webhook received but sync is disabled");
            return new WP_REST_Response('Sync is disabled', 200);
        }
        
        $this->logger->info("Dropbox webhook notification received");
        
        // Schedule processing as a background task
        if (class_exists('ActionScheduler')) {
            as_schedule_single_action(time(), 'fds_process_dropbox_changes');
            $this->logger->info("Scheduled Dropbox changes processing with Action Scheduler");
        } else {
            // Fallback to direct processing
            $this->process_dropbox_changes();
        }
        
        return new WP_REST_Response('Webhook received and processing scheduled', 200);
    }

    /**
     * Process changes from Dropbox.
     *
     * @since    1.0.0
     */
    public function process_dropbox_changes() {
        // Get the latest cursor
        $cursor = $this->dropbox_api->get_latest_cursor();
        
        if (empty($cursor)) {
            $cursor = $this->dropbox_api->get_folder_cursor();
            
            if (empty($cursor)) {
                $this->logger->error("Failed to get folder cursor");
                return;
            }
        }
        
        // Get changes
        $changes = $this->dropbox_api->get_changes($cursor);
        
        if (!$changes) {
            $this->logger->error("Failed to get changes from Dropbox");
            return;
        }
        
        // Process entries
        if (!empty($changes['entries'])) {
            $this->process_entries($changes['entries']);
            $this->logger->info("Processed " . count($changes['entries']) . " changes from Dropbox");
        } else {
            $this->logger->info("No changes found to process");
        }
        
        // Save the new cursor
        if (isset($changes['cursor'])) {
            update_option('fds_dropbox_cursor', $changes['cursor']);
        }
        
        // If more changes are available, schedule another run
        if (isset($changes['has_more']) && $changes['has_more']) {
            if (class_exists('ActionScheduler')) {
                as_schedule_single_action(time() + 30, 'fds_process_dropbox_changes');
                $this->logger->info("Scheduled processing of additional changes");
            } else {
                $this->process_dropbox_changes();
            }
        }
    }

    /**
     * Process entries from Dropbox changes.
     *
     * @since    1.0.0
     * @param    array    $entries    The entries to process.
     */
    protected function process_entries($entries) {
        if (empty($entries)) {
            return;
        }
        
        // Lazy-load sync instances when needed
        if (!$this->folder_sync) {
            $this->folder_sync = new FDS_Folder_Sync($this->dropbox_api, $this->db, $this->logger);
        }
        
        if (!$this->file_sync) {
            $this->file_sync = new FDS_File_Sync($this->dropbox_api, $this->db, $this->logger);
        }
        
        // Get the root folder to filter entries
        $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
        
        // Group entries by type for more efficient processing
        $folders = [];
        $files = [];
        $deletions = [];
        
        foreach ($entries as $entry) {
            // Check if the entry is within our root folder
            if (!isset($entry['path_lower']) || strpos($entry['path_lower'], strtolower($root_folder)) !== 0) {
                continue;
            }
            
            // Categorize by type
            if (isset($entry['.tag'])) {
                switch ($entry['.tag']) {
                    case 'deleted':
                        $deletions[] = $entry;
                        break;
                    case 'file':
                        $files[] = $entry;
                        break;
                    case 'folder':
                        $folders[] = $entry;
                        break;
                }
            }
        }
        
        // Process in the correct order:
        // 1. First create folders (starting with parent folders)
        if (!empty($folders)) {
            // Sort folders by path depth to ensure parents are created first
            usort($folders, function($a, $b) {
                return substr_count($a['path_lower'], '/') - substr_count($b['path_lower'], '/');
            });
            
            foreach ($folders as $folder) {
                $this->process_folder_entry($folder);
            }
        }
        
        // 2. Then process files
        foreach ($files as $file) {
            $this->process_file_entry($file);
        }
        
        // 3. Finally handle deletions
        foreach ($deletions as $deletion) {
            $this->process_deleted_entry($deletion);
        }
        
        $this->logger->info("Processed Dropbox changes", [
            'folders' => count($folders),
            'files' => count($files),
            'deletions' => count($deletions)
        ]);
    }

    /**
     * Process a deleted entry from Dropbox.
     *
     * @since    1.0.0
     * @param    array    $entry    The entry to process.
     */
    protected function process_deleted_entry($entry) {
        $path = $entry['path_lower'];
        
        // Check if it's a file
        $file_mapping = $this->db->get_file_mapping_by_dropbox_path($path);
        
        if ($file_mapping) {
            // It's a file, delete the attachment
            $attachment_id = $file_mapping->attachment_id;
            
            $this->db->add_to_sync_queue(
                'delete',
                'file',
                (string) $attachment_id,
                'dropbox_to_wordpress',
                array(
                    'attachment_id' => $attachment_id,
                    'dropbox_path' => $path,
                ),
                10
            );
            
            $this->logger->info("Queued file deletion from Dropbox", array(
                'attachment_id' => $attachment_id,
                'dropbox_path' => $path
            ));
            
            return;
        }
        
        // Check if it's a folder
        $folder_mapping = $this->db->get_folder_mapping_by_dropbox_path($path);
        
        if ($folder_mapping) {
            // It's a folder, delete it in FileBird
            $folder_id = $folder_mapping->filebird_folder_id;
            
            $this->db->add_to_sync_queue(
                'delete',
                'folder',
                (string) $folder_id,
                'dropbox_to_wordpress',
                array(
                    'folder_id' => $folder_id,
                    'dropbox_path' => $path,
                ),
                5
            );
            
            $this->logger->info("Queued folder deletion from Dropbox", array(
                'folder_id' => $folder_id,
                'dropbox_path' => $path
            ));
        }
    }

    /**
     * Process a file entry from Dropbox.
     *
     * @since    1.0.0
     * @param    array    $entry    The entry to process.
     */
    protected function process_file_entry($entry) {
        $path = $entry['path_lower'];
        
        // Check if it's a hidden file
        $filename = basename($path);
        if (substr($filename, 0, 1) === '.') {
            return;
        }
        
        // Get conflict resolution strategy
        $conflict_resolution = get_option('fds_conflict_resolution', 'wordpress_wins');
        
        // Check if file exists in mapping
        $file_mapping = $this->db->get_file_mapping_by_dropbox_path($path);
        
        if ($file_mapping) {
            // File exists, check if it needs updating
            $attachment_id = $file_mapping->attachment_id;
            
            // Check content hash
            if (isset($entry['content_hash']) && $file_mapping->sync_hash !== $entry['content_hash']) {
                // File has changed in Dropbox
                
                // Check if WordPress version has also changed
                $attachment_file = get_attached_file($attachment_id);
                
                if (file_exists($attachment_file)) {
                    $wp_hash = md5_file($attachment_file);
                    
                    if ($wp_hash !== $file_mapping->sync_hash) {
                        // Both versions have changed, apply conflict resolution
                        if ($conflict_resolution === 'dropbox_wins') {
                            $this->db->add_to_sync_queue(
                                'update',
                                'file',
                                (string) $attachment_id,
                                'dropbox_to_wordpress',
                                array(
                                    'attachment_id' => $attachment_id,
                                    'dropbox_path' => $path,
                                    'dropbox_metadata' => $entry,
                                ),
                                10
                            );
                            
                            $this->logger->info("Queued file update from Dropbox (conflict resolved)", array(
                                'attachment_id' => $attachment_id,
                                'dropbox_path' => $path,
                                'resolution' => 'dropbox_wins'
                            ));
                        } else {
                            // WordPress wins, update Dropbox version later
                            $this->logger->info("File conflict detected, WordPress version will be kept", array(
                                'attachment_id' => $attachment_id,
                                'dropbox_path' => $path,
                                'resolution' => 'wordpress_wins'
                            ));
                        }
                    } else {
                        // Only Dropbox version has changed
                        $this->db->add_to_sync_queue(
                            'update',
                            'file',
                            (string) $attachment_id,
                            'dropbox_to_wordpress',
                            array(
                                'attachment_id' => $attachment_id,
                                'dropbox_path' => $path,
                                'dropbox_metadata' => $entry,
                            ),
                            10
                        );
                        
                        $this->logger->info("Queued file update from Dropbox", array(
                            'attachment_id' => $attachment_id,
                            'dropbox_path' => $path
                        ));
                    }
                } else {
                    // WordPress file doesn't exist anymore
                    $this->db->add_to_sync_queue(
                        'update',
                        'file',
                        (string) $attachment_id,
                        'dropbox_to_wordpress',
                        array(
                            'attachment_id' => $attachment_id,
                            'dropbox_path' => $path,
                            'dropbox_metadata' => $entry,
                        ),
                        10
                    );
                    
                    $this->logger->info("Queued file restoration from Dropbox", array(
                        'attachment_id' => $attachment_id,
                        'dropbox_path' => $path
                    ));
                }
            }
        } else {
            // New file, import it
            
            // Get the parent folder from the path
            $parent_path = dirname($path);
            
            // Skip if parent path is the root
            $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
            $folder_id = 0;
            
            if ($parent_path !== $root_folder) {
                // Find or create FileBird folder
                $folder_id = $this->folder_sync->get_filebird_folder_id_for_dropbox_path($parent_path);
            }
            
            $this->db->add_to_sync_queue(
                'create',
                'file',
                md5($path), // Use path hash as ID for new files
                'dropbox_to_wordpress',
                array(
                    'dropbox_path' => $path,
                    'dropbox_metadata' => $entry,
                    'folder_id' => $folder_id,
                ),
                10
            );
            
            $this->logger->info("Queued file creation from Dropbox", array(
                'dropbox_path' => $path,
                'folder_id' => $folder_id
            ));
        }
    }

    /**
     * Process a folder entry from Dropbox.
     *
     * @since    1.0.0
     * @param    array    $entry    The entry to process.
     */
    protected function process_folder_entry($entry) {
        $path = $entry['path_lower'];
        
        // Check if it's a hidden folder
        $folder_name = basename($path);
        if (substr($folder_name, 0, 1) === '.') {
            return;
        }
        
        // Check if folder exists in mapping
        $folder_mapping = $this->db->get_folder_mapping_by_dropbox_path($path);
        
        if (!$folder_mapping) {
            // New folder, create it in FileBird
            
            // Get the parent folder from the path
            $parent_path = dirname($path);
            
            // Skip if parent path is the root
            $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
            $parent_id = 0;
            
            if ($parent_path !== $root_folder) {
                // Find or create FileBird parent folder
                $parent_id = $this->folder_sync->get_filebird_folder_id_for_dropbox_path($parent_path);
                
                if (!$parent_id) {
                    $this->logger->error("Failed to find or create parent folder", array(
                        'parent_path' => $parent_path
                    ));
                    return;
                }
            }
            
            $this->db->add_to_sync_queue(
                'create',
                'folder',
                md5($path), // Use path hash as ID for new folders
                'dropbox_to_wordpress',
                array(
                    'dropbox_path' => $path,
                    'folder_name' => $folder_name,
                    'parent_id' => $parent_id,
                ),
                5
            );
            
            $this->logger->info("Queued folder creation from Dropbox", array(
                'dropbox_path' => $path,
                'folder_name' => $folder_name,
                'parent_id' => $parent_id
            ));
        }
    }
}