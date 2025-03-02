<?php
/**
 * Handles file synchronization between WordPress and Dropbox.
 *
 * This class provides methods to sync files between WordPress and Dropbox.
 *
 * @since      1.0.0
 */
class FDS_File_Sync {

    /**
     * The Dropbox API instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Dropbox_API    $dropbox_api    The Dropbox API instance.
     */
    protected $dropbox_api;

    /**
     * The database instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_DB    $db    The database instance.
     */
    protected $db;

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FDS_Logger    $logger    The logger instance.
     */
    protected $logger;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    FDS_Dropbox_API    $dropbox_api    The Dropbox API instance.
     * @param    FDS_DB            $db              The database instance.
     * @param    FDS_Logger        $logger          The logger instance.
     */
    public function __construct($dropbox_api, $db, $logger) {
        $this->dropbox_api = $dropbox_api;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Handle file creation in WordPress.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     */
    public function on_file_added($attachment_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        // Get attachment metadata
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            $this->logger->error("Attachment file not found", array(
                'attachment_id' => $attachment_id,
                'file_path' => $file_path
            ));
            return;
        }
        
        // Determine folder in FileBird
        $folder_id = $this->get_filebird_folder_for_attachment($attachment_id);
        
        // Determine Dropbox path
        $dropbox_path = $this->get_dropbox_path_for_attachment($attachment_id, $folder_id);
        
        if (!$dropbox_path) {
            $this->logger->error("Failed to determine Dropbox path for attachment", array(
                'attachment_id' => $attachment_id,
                'folder_id' => $folder_id
            ));
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'create',
            'file',
            (string) $attachment_id,
            'wordpress_to_dropbox',
            array(
                'attachment_id' => $attachment_id,
                'local_path' => $file_path,
                'dropbox_path' => $dropbox_path,
                'folder_id' => $folder_id,
            ),
            10 // Normal priority for file operations
        );
        
        $this->logger->info("Added file creation to queue", array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $dropbox_path
        ));
    }

    /**
     * Handle file deletion in WordPress.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     */
    public function on_file_deleted($attachment_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $mapping = $this->db->get_file_mapping_by_attachment_id($attachment_id);
        
        if (!$mapping) {
            $this->logger->notice("File mapping not found for deleted file", array(
                'attachment_id' => $attachment_id
            ));
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'delete',
            'file',
            (string) $attachment_id,
            'wordpress_to_dropbox',
            array(
                'attachment_id' => $attachment_id,
                'dropbox_path' => $mapping->dropbox_path,
                'dropbox_file_id' => $mapping->dropbox_file_id,
            ),
            10 // Normal priority for file operations
        );
        
        $this->logger->info("Added file deletion to queue", array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $mapping->dropbox_path
        ));
    }

    /**
     * Handle file update in WordPress.
     *
     * @since    1.0.0
     * @param    array     $metadata        The attachment metadata.
     * @param    int       $attachment_id   The attachment ID.
     */
    public function on_file_updated($metadata, $attachment_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        // Get attachment file path
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            $this->logger->error("Updated attachment file not found", array(
                'attachment_id' => $attachment_id,
                'file_path' => $file_path
            ));
            return;
        }
        
        $mapping = $this->db->get_file_mapping_by_attachment_id($attachment_id);
        
        if (!$mapping) {
            // If no mapping exists, treat this as a new file
            $this->on_file_added($attachment_id);
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'update',
            'file',
            (string) $attachment_id,
            'wordpress_to_dropbox',
            array(
                'attachment_id' => $attachment_id,
                'local_path' => $file_path,
                'dropbox_path' => $mapping->dropbox_path,
                'dropbox_file_id' => $mapping->dropbox_file_id,
            ),
            10 // Normal priority for file operations
        );
        
        $this->logger->info("Added file update to queue", array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $mapping->dropbox_path
        ));
        
        // Handle thumbnail files if they exist
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_dir = dirname($file_path);
            
            foreach ($metadata['sizes'] as $size => $info) {
                if (isset($info['file'])) {
                    $thumb_path = $base_dir . '/' . $info['file'];
                    $dropbox_thumb_path = dirname($mapping->dropbox_path) . '/' . $info['file'];
                    
                    // Add thumbnail update to queue with lower priority
                    $this->db->add_to_sync_queue(
                        'update',
                        'file',
                        (string) $attachment_id . '_' . $size,
                        'wordpress_to_dropbox',
                        array(
                            'attachment_id' => $attachment_id,
                            'size' => $size,
                            'local_path' => $thumb_path,
                            'dropbox_path' => $dropbox_thumb_path,
                        ),
                        15 // Lower priority for thumbnail operations
                    );
                    
                    $this->logger->debug("Added thumbnail update to queue", array(
                        'attachment_id' => $attachment_id,
                        'size' => $size,
                        'dropbox_path' => $dropbox_thumb_path
                    ));
                }
            }
        }
        
        return $metadata;
    }

    /**
     * Handle file move in WordPress (when a file is assigned to a different folder).
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @param    int       $folder_id        The new folder ID.
     */
    public function on_file_moved($attachment_id, $folder_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $mapping = $this->db->get_file_mapping_by_attachment_id($attachment_id);
        
        if (!$mapping) {
            // If no mapping exists, treat this as a new file
            $this->on_file_added($attachment_id);
            return;
        }
        
        // Determine new Dropbox path
        $new_dropbox_path = $this->get_dropbox_path_for_attachment($attachment_id, $folder_id);
        
        if (!$new_dropbox_path || $mapping->dropbox_path === $new_dropbox_path) {
            // Path didn't change or couldn't be determined
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'move',
            'file',
            (string) $attachment_id,
            'wordpress_to_dropbox',
            array(
                'attachment_id' => $attachment_id,
                'old_path' => $mapping->dropbox_path,
                'new_path' => $new_dropbox_path,
                'folder_id' => $folder_id,
            ),
            10 // Normal priority for file operations
        );
        
        $this->logger->info("Added file move to queue", array(
            'attachment_id' => $attachment_id,
            'old_path' => $mapping->dropbox_path,
            'new_path' => $new_dropbox_path
        ));
        
        // Handle thumbnail files if they exist
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $info) {
                if (isset($info['file'])) {
                    $old_thumb_path = dirname($mapping->dropbox_path) . '/' . $info['file'];
                    $new_thumb_path = dirname($new_dropbox_path) . '/' . $info['file'];
                    
                    // Add thumbnail move to queue with lower priority
                    $this->db->add_to_sync_queue(
                        'move',
                        'file',
                        (string) $attachment_id . '_' . $size,
                        'wordpress_to_dropbox',
                        array(
                            'attachment_id' => $attachment_id,
                            'size' => $size,
                            'old_path' => $old_thumb_path,
                            'new_path' => $new_thumb_path,
                        ),
                        15 // Lower priority for thumbnail operations
                    );
                    
                    $this->logger->debug("Added thumbnail move to queue", array(
                        'attachment_id' => $attachment_id,
                        'size' => $size,
                        'old_path' => $old_thumb_path,
                        'new_path' => $new_thumb_path
                    ));
                }
            }
        }
    }

    /**
     * Process file creation task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_file_create_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['local_path']) || empty($data['dropbox_path'])) {
                throw new Exception("Missing required file information");
            }
            
            if (!file_exists($data['local_path'])) {
                throw new Exception("Local file does not exist: " . $data['local_path']);
            }
            
            // Validate file size
            $file_size = filesize($data['local_path']);
            if ($file_size === 0) {
                throw new Exception("File is empty: " . $data['local_path']);
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Upload the file to Dropbox
            $result = $this->dropbox_api->upload_file($data['local_path'], $data['dropbox_path'], true);
            
            if (!$result) {
                throw new Exception("Dropbox API upload failed");
            }
            
            if (!isset($result['id'])) {
                throw new Exception("Invalid response from Dropbox API: missing file ID");
            }
            
            // Calculate content hash for verification
            $sync_hash = md5_file($data['local_path']);
            if (!$sync_hash) {
                throw new Exception("Failed to calculate file hash");
            }
            
            // Update mapping in database
            $mapping_result = $this->db->add_or_update_file_mapping(
                $data['attachment_id'],
                $data['dropbox_path'],
                $result['id'],
                $sync_hash
            );
            
            if (!$mapping_result) {
                throw new Exception("Failed to update file mapping in database");
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("File uploaded to Dropbox successfully", [
                'attachment_id' => $data['attachment_id'],
                'dropbox_path' => $data['dropbox_path'],
                'dropbox_file_id' => $result['id'],
                'file_size' => $file_size,
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("File create task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'attachment_id' => isset($data['attachment_id']) ? $data['attachment_id'] : 'unknown',
                'dropbox_path' => isset($data['dropbox_path']) ? $data['dropbox_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Process file update task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_file_update_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Check if this is a thumbnail task
            $is_thumbnail = isset($data['size']) && !empty($data['size']);
            
            // Validate input data
            if (empty($data['local_path']) || empty($data['dropbox_path'])) {
                throw new Exception("Missing required file information");
            }
            
            if (!file_exists($data['local_path'])) {
                throw new Exception("Local file does not exist: " . $data['local_path']);
            }
            
            // Validate file size
            $file_size = filesize($data['local_path']);
            if ($file_size === 0) {
                throw new Exception("File is empty: " . $data['local_path']);
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Upload the file to Dropbox
            $result = $this->dropbox_api->upload_file($data['local_path'], $data['dropbox_path'], true);
            
            if (!$result) {
                throw new Exception("Dropbox API upload failed");
            }
            
            if (!isset($result['id'])) {
                throw new Exception("Invalid response from Dropbox API: missing file ID");
            }
            
            if (!$is_thumbnail) {
                // Calculate content hash for verification
                $sync_hash = md5_file($data['local_path']);
                if (!$sync_hash) {
                    throw new Exception("Failed to calculate file hash");
                }
                
                // Update mapping in database
                $mapping_result = $this->db->add_or_update_file_mapping(
                    $data['attachment_id'],
                    $data['dropbox_path'],
                    $result['id'],
                    $sync_hash
                );
                
                if (!$mapping_result) {
                    throw new Exception("Failed to update file mapping in database");
                }
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            
            if ($is_thumbnail) {
                $this->logger->info("Thumbnail uploaded to Dropbox successfully", [
                    'attachment_id' => $data['attachment_id'],
                    'size' => $data['size'],
                    'dropbox_path' => $data['dropbox_path'],
                    'file_size' => $file_size,
                    'elapsed_seconds' => round($elapsed, 2)
                ]);
            } else {
                $this->logger->info("File updated in Dropbox successfully", [
                    'attachment_id' => $data['attachment_id'],
                    'dropbox_path' => $data['dropbox_path'],
                    'dropbox_file_id' => $result['id'],
                    'file_size' => $file_size,
                    'elapsed_seconds' => round($elapsed, 2)
                ]);
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("File update task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'attachment_id' => isset($data['attachment_id']) ? $data['attachment_id'] : 'unknown',
                'dropbox_path' => isset($data['dropbox_path']) ? $data['dropbox_path'] : 'unknown',
                'is_thumbnail' => isset($is_thumbnail) ? $is_thumbnail : false
            ]);
            return false;
        }
    }

    /**
     * Process file delete task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_file_delete_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['dropbox_path'])) {
                throw new Exception("Missing dropbox path in file delete task");
            }
            
            if (empty($data['attachment_id'])) {
                throw new Exception("Missing attachment ID in file delete task");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Delete the file from Dropbox
            $result = $this->dropbox_api->delete_file($data['dropbox_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API delete failed");
            }
            
            // Delete mapping
            $mapping_result = $this->db->delete_file_mapping_by_attachment_id($data['attachment_id']);
            
            if (!$mapping_result) {
                throw new Exception("Failed to delete file mapping from database");
            }
            
            // Try to delete thumbnails
            $deleted_thumbs = 0;
            $metadata = wp_get_attachment_metadata($data['attachment_id']);
            
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $info) {
                    if (isset($info['file'])) {
                        $thumb_path = dirname($data['dropbox_path']) . '/' . $info['file'];
                        
                        $thumb_result = $this->dropbox_api->delete_file($thumb_path);
                        
                        if ($thumb_result) {
                            $deleted_thumbs++;
                            $this->logger->debug("Deleted thumbnail from Dropbox", [
                                'attachment_id' => $data['attachment_id'],
                                'size' => $size,
                                'dropbox_path' => $thumb_path
                            ]);
                        }
                    }
                }
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("File deleted from Dropbox successfully", [
                'attachment_id' => $data['attachment_id'],
                'dropbox_path' => $data['dropbox_path'],
                'deleted_thumbnails' => $deleted_thumbs,
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("File delete task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'attachment_id' => isset($data['attachment_id']) ? $data['attachment_id'] : 'unknown',
                'dropbox_path' => isset($data['dropbox_path']) ? $data['dropbox_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Process file move task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_file_move_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Check if this is a thumbnail task
            $is_thumbnail = isset($data['size']) && !empty($data['size']);
            
            // Validate input data
            if (empty($data['old_path']) || empty($data['new_path'])) {
                throw new Exception("Missing path information in file move task");
            }
            
            if (!$is_thumbnail && empty($data['attachment_id'])) {
                throw new Exception("Missing attachment ID in file move task");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Move the file in Dropbox
            $result = $this->dropbox_api->move_file($data['old_path'], $data['new_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API move failed");
            }
            
            if (!isset($result['id']) && !$is_thumbnail) {
                throw new Exception("Invalid response from Dropbox API: missing file ID");
            }
            
            // Update mapping for main file
            if (!$is_thumbnail) {
                $mapping = $this->db->get_file_mapping_by_attachment_id($data['attachment_id']);
                
                if (!$mapping) {
                    throw new Exception("File mapping not found for attachment ID: " . $data['attachment_id']);
                }
                
                $mapping_result = $this->db->add_or_update_file_mapping(
                    $data['attachment_id'],
                    $data['new_path'],
                    isset($result['id']) ? $result['id'] : $mapping->dropbox_file_id,
                    $mapping->sync_hash
                );
                
                if (!$mapping_result) {
                    throw new Exception("Failed to update file mapping in database");
                }
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            
            if ($is_thumbnail) {
                $this->logger->info("Thumbnail moved in Dropbox successfully", [
                    'attachment_id' => $data['attachment_id'],
                    'size' => $data['size'],
                    'old_path' => $data['old_path'],
                    'new_path' => $data['new_path'],
                    'elapsed_seconds' => round($elapsed, 2)
                ]);
            } else {
                $this->logger->info("File moved in Dropbox successfully", [
                    'attachment_id' => $data['attachment_id'],
                    'old_path' => $data['old_path'],
                    'new_path' => $data['new_path'],
                    'elapsed_seconds' => round($elapsed, 2)
                ]);
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("File move task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'attachment_id' => isset($data['attachment_id']) ? $data['attachment_id'] : 'unknown',
                'old_path' => isset($data['old_path']) ? $data['old_path'] : 'unknown',
                'new_path' => isset($data['new_path']) ? $data['new_path'] : 'unknown',
                'is_thumbnail' => isset($is_thumbnail) ? $is_thumbnail : false
            ]);
            return false;
        }
    }

    /**
     * Process a file upload from Dropbox to WordPress.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path      The Dropbox file path.
     * @param    array     $dropbox_metadata  The Dropbox file metadata.
     * @param    int       $folder_id         The FileBird folder ID to assign to.
     * @return   int|false                   The attachment ID or false on failure.
     */
    public function process_file_from_dropbox($dropbox_path, $dropbox_metadata, $folder_id = 0) {
        // Check if file is already in WordPress
        $existing_mapping = $this->db->get_file_mapping_by_dropbox_path($dropbox_path);
        
        if ($existing_mapping) {
            // File already exists, check if it needs updating
            $attachment_id = $existing_mapping->attachment_id;
            
            // Check if we should update
            if (isset($dropbox_metadata['content_hash']) && $existing_mapping->sync_hash !== $dropbox_metadata['content_hash']) {
                return $this->update_wordpress_file_from_dropbox($attachment_id, $dropbox_path, $dropbox_metadata);
            }
            
            // File exists and is up to date
            return $attachment_id;
        }
        
        // Check if it's not a supported file type
        $file_name = basename($dropbox_path);
        $file_type = wp_check_filetype($file_name);
        
        if (!$file_type['type']) {
            $this->logger->notice("Unsupported file type from Dropbox", array(
                'dropbox_path' => $dropbox_path,
                'file_name' => $file_name
            ));
            return false;
        }
        
        // Download the file to a temporary location
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/fds-temp';
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $temp_file = $temp_dir . '/' . $file_name;
        
        $download_result = $this->dropbox_api->download_file($dropbox_path, $temp_file);
        
        if (!$download_result) {
            $this->logger->error("Failed to download file from Dropbox", array(
                'dropbox_path' => $dropbox_path,
                'temp_file' => $temp_file
            ));
            return false;
        }
        
        // Prepare file data for WordPress
        $file_data = file_get_contents($temp_file);
        $upload = wp_upload_bits($file_name, null, $file_data);
        
        if ($upload['error']) {
            $this->logger->error("Failed to save file in WordPress", array(
                'dropbox_path' => $dropbox_path,
                'error' => $upload['error']
            ));
            @unlink($temp_file);
            return false;
        }
        
        // Create the attachment
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $file_name),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $upload['url'],
        );
        
        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        
        if (is_wp_error($attachment_id)) {
            $this->logger->error("Failed to create attachment in WordPress", array(
                'dropbox_path' => $dropbox_path,
                'error' => $attachment_id->get_error_message()
            ));
            @unlink($temp_file);
            @unlink($upload['file']);
            return false;
        }
        
        // Include image.php if it's not already loaded
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Generate metadata and thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Assign to folder if specified
        if ($folder_id > 0) {
            if (class_exists('FileBird\\Model\\Folder')) {
                \FileBird\Model\Folder::setFoldersForPosts($attachment_id, $folder_id);
            }
        }
        
        // Add mapping
        $sync_hash = isset($dropbox_metadata['content_hash']) ? $dropbox_metadata['content_hash'] : md5(file_get_contents($upload['file']));
        $this->db->add_or_update_file_mapping(
            $attachment_id,
            $dropbox_path,
            $dropbox_metadata['id'],
            $sync_hash
        );
        
        $this->logger->info("Created attachment from Dropbox file", array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $dropbox_path,
            'folder_id' => $folder_id
        ));
        
        // Clean up temporary file
        @unlink($temp_file);
        
        return $attachment_id;
    }

    /**
     * Update a WordPress file from Dropbox.
     *
     * @since    1.0.0
     * @param    int       $attachment_id     The attachment ID.
     * @param    string    $dropbox_path      The Dropbox file path.
     * @param    array     $dropbox_metadata  The Dropbox file metadata.
     * @return   int|false                   The attachment ID or false on failure.
     */
    protected function update_wordpress_file_from_dropbox($attachment_id, $dropbox_path, $dropbox_metadata) {
        // Check if attachment still exists
        $attachment = get_post($attachment_id);
        
        if (!$attachment) {
            $this->logger->error("Attachment no longer exists", array(
                'attachment_id' => $attachment_id,
                'dropbox_path' => $dropbox_path
            ));
            $this->db->delete_file_mapping_by_attachment_id($attachment_id);
            return false;
        }
        
        // Get attachment file path
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path) {
            $this->logger->error("Attachment file path not found", array(
                'attachment_id' => $attachment_id
            ));
            return false;
        }
        
        // Download the file from Dropbox
        $download_result = $this->dropbox_api->download_file($dropbox_path, $file_path);
        
        if (!$download_result) {
            $this->logger->error("Failed to download updated file from Dropbox", array(
                'attachment_id' => $attachment_id,
                'dropbox_path' => $dropbox_path,
                'file_path' => $file_path
            ));
            return false;
        }
        
        // Include image.php if it's not already loaded
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Generate metadata and thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Update mapping with new hash
        $sync_hash = isset($dropbox_metadata['content_hash']) ? $dropbox_metadata['content_hash'] : md5(file_get_contents($file_path));
        $this->db->add_or_update_file_mapping(
            $attachment_id,
            $dropbox_path,
            $dropbox_metadata['id'],
            $sync_hash
        );
        
        $this->logger->info("Updated attachment from Dropbox file", array(
            'attachment_id' => $attachment_id,
            'dropbox_path' => $dropbox_path
        ));
        
        return $attachment_id;
    }

    /**
     * Get the FileBird folder for an attachment.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   int                        The folder ID.
     */
    protected function get_filebird_folder_for_attachment($attachment_id) {
        if (!class_exists('FileBird\\Model\\Folder')) {
            return 0;
        }
        
        $folders = \FileBird\Model\Folder::getFolderFromPostId($attachment_id);
        
        if (is_array($folders) && !empty($folders)) {
            return intval($folders[0]->folder_id);
        }
        
        return 0;
    }

    /**
     * Get the Dropbox path for an attachment.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @param    int       $folder_id        The folder ID.
     * @return   string                     The Dropbox path.
     */
    protected function get_dropbox_path_for_attachment($attachment_id, $folder_id = 0) {
        // Get attachment file name
        $file_name = basename(get_attached_file($attachment_id));
        
        if (!$file_name) {
            return false;
        }
        
        // If folder ID is not provided, try to get it
        if (!$folder_id) {
            $folder_id = $this->get_filebird_folder_for_attachment($attachment_id);
        }
        
        if ($folder_id > 0) {
            // Get folder path in Dropbox
            $folder_sync = new FDS_Folder_Sync($this->dropbox_api, $this->db, $this->logger);
            $folder_path = $folder_sync->get_dropbox_path_for_filebird_folder($folder_id);
            
            if (!$folder_path) {
                return false;
            }
            
            return $folder_path . '/' . $file_name;
        } else {
            // Uncategorized/root folder
            $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
            return $root_folder . '/' . $file_name;
        }
    }
}