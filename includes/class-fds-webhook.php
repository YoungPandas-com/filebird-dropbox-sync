<?php
/**
 * Handles Dropbox webhook notifications.
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
        
        // Register the webhook with Dropbox if not already registered
        if (get_option('fds_sync_enabled', false) && !get_option('fds_webhook_registered', false)) {
            $result = $this->dropbox_api->register_webhook();
            if ($result) {
                update_option('fds_webhook_registered', true);
                $this->logger->info("Webhook successfully registered with Dropbox");
            }
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
            return new WP_REST_Response('No challenge parameter provided', 400);
        }
        
        $this->logger->info("Dropbox challenge received", array(
            'challenge' => $challenge
        ));
        
        // Create a plain text response without JSON encoding
        $response = new WP_REST_Response($challenge);
        $response->set_headers(['Content-Type' => 'text/plain']);
        
        return $response;
    }

    /**
     * Handle the Dropbox webhook notification.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function handle_webhook($request) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return new WP_REST_Response('Sync is disabled', 200);
        }
        
        $this->logger->info("Dropbox webhook received");
        
        // Process the changes
        $this->process_dropbox_changes();
        
        return new WP_REST_Response('Webhook received', 200);
    }

    /**
     * Process changes from Dropbox.
     *
     * @since    1.0.0
     */
    protected function process_dropbox_changes() {
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
        $this->process_entries($changes['entries']);
        
        // Save the new cursor
        if (isset($changes['cursor'])) {
            update_option('fds_dropbox_cursor', $changes['cursor']);
        }
        
        // If more changes are available, process them
        if (isset($changes['has_more']) && $changes['has_more']) {
            $this->process_dropbox_changes();
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
        
        foreach ($entries as $entry) {
            // Check if the entry is within our root folder
            if (!isset($entry['path_lower']) || strpos($entry['path_lower'], strtolower($root_folder)) !== 0) {
                continue;
            }
            
            // Process entry based on type
            if (isset($entry['.tag'])) {
                switch ($entry['.tag']) {
                    case 'deleted':
                        $this->process_deleted_entry($entry);
                        break;
                    case 'file':
                        $this->process_file_entry($entry);
                        break;
                    case 'folder':
                        $this->process_folder_entry($entry);
                        break;
                }
            }
        }
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