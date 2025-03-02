<?php
/**
 * Handles folder synchronization between FileBird and Dropbox.
 *
 * This class provides methods to sync folders between FileBird and Dropbox.
 *
 * @since      1.0.0
 */
class FDS_Folder_Sync {

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
     * Handle folder creation in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @param    array     $folder_data  The folder data.
     */
    public function on_folder_created($folder_id, $folder_data) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $folder_path = $this->get_dropbox_path_for_filebird_folder($folder_id);
        
        if (!$folder_path) {
            $this->logger->error("Failed to determine Dropbox path for new FileBird folder $folder_id", array(
                'folder_id' => $folder_id,
                'folder_data' => $folder_data
            ));
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'create',
            'folder',
            (string) $folder_id,
            'wordpress_to_dropbox',
            array(
                'folder_id' => $folder_id,
                'folder_name' => $folder_data['title'],
                'folder_path' => $folder_path,
            ),
            5 // Higher priority for folder operations
        );
        
        $this->logger->info("Added folder creation to queue", array(
            'folder_id' => $folder_id,
            'dropbox_path' => $folder_path
        ));
    }

    /**
     * Handle folder renaming in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id     The folder ID.
     * @param    string    $new_name      The new folder name.
     */
    public function on_folder_renamed($folder_id, $new_name) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $mapping = $this->db->get_folder_mapping_by_folder_id($folder_id);
        
        if (!$mapping) {
            $this->logger->error("Folder mapping not found for renamed folder", array(
                'folder_id' => $folder_id,
                'new_name' => $new_name
            ));
            return;
        }
        
        $old_path = $mapping->dropbox_path;
        $new_path = dirname($old_path) . '/' . sanitize_file_name($new_name);
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'rename',
            'folder',
            (string) $folder_id,
            'wordpress_to_dropbox',
            array(
                'folder_id' => $folder_id,
                'old_path' => $old_path,
                'new_path' => $new_path,
                'new_name' => $new_name,
            ),
            5 // Higher priority for folder operations
        );
        
        $this->logger->info("Added folder rename to queue", array(
            'folder_id' => $folder_id,
            'old_path' => $old_path,
            'new_path' => $new_path
        ));
    }

    /**
     * Handle folder deletion in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     */
    public function on_folder_deleted($folder_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $mapping = $this->db->get_folder_mapping_by_folder_id($folder_id);
        
        if (!$mapping) {
            $this->logger->notice("Folder mapping not found for deleted folder", array(
                'folder_id' => $folder_id
            ));
            return;
        }
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'delete',
            'folder',
            (string) $folder_id,
            'wordpress_to_dropbox',
            array(
                'folder_id' => $folder_id,
                'folder_path' => $mapping->dropbox_path,
            ),
            5 // Higher priority for folder operations
        );
        
        $this->logger->info("Added folder deletion to queue", array(
            'folder_id' => $folder_id,
            'dropbox_path' => $mapping->dropbox_path
        ));
    }

    /**
     * Handle folder move in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id       The folder ID.
     * @param    int       $new_parent_id   The new parent folder ID.
     */
    public function on_folder_moved($folder_id, $new_parent_id) {
        // Check if sync is enabled
        if (!get_option('fds_sync_enabled', false)) {
            return;
        }
        
        $mapping = $this->db->get_folder_mapping_by_folder_id($folder_id);
        
        if (!$mapping) {
            $this->logger->error("Folder mapping not found for moved folder", array(
                'folder_id' => $folder_id,
                'new_parent_id' => $new_parent_id
            ));
            return;
        }
        
        $old_path = $mapping->dropbox_path;
        $folder_name = basename($old_path);
        
        // Get the new path based on the new parent
        $new_parent_path = '';
        if ($new_parent_id > 0) {
            $parent_mapping = $this->db->get_folder_mapping_by_folder_id($new_parent_id);
            if ($parent_mapping) {
                $new_parent_path = $parent_mapping->dropbox_path;
            } else {
                $this->logger->error("Parent folder mapping not found", array(
                    'folder_id' => $folder_id,
                    'new_parent_id' => $new_parent_id
                ));
                return;
            }
        } else {
            // Root folder
            $new_parent_path = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
        }
        
        $new_path = $new_parent_path . '/' . $folder_name;
        
        // Add to queue
        $this->db->add_to_sync_queue(
            'move',
            'folder',
            (string) $folder_id,
            'wordpress_to_dropbox',
            array(
                'folder_id' => $folder_id,
                'old_path' => $old_path,
                'new_path' => $new_path,
                'new_parent_id' => $new_parent_id,
            ),
            5 // Higher priority for folder operations
        );
        
        $this->logger->info("Added folder move to queue", array(
            'folder_id' => $folder_id,
            'old_path' => $old_path,
            'new_path' => $new_path
        ));
    }

    /**
     * Process folder creation task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_folder_create_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['folder_path'])) {
                throw new Exception("Missing folder path in folder create task");
            }
            
            if (empty($data['folder_id']) || !is_numeric($data['folder_id'])) {
                throw new Exception("Missing or invalid folder ID");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Create the folder in Dropbox
            $result = $this->dropbox_api->create_folder($data['folder_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API create folder failed");
            }
            
            // Update mapping
            $sync_hash = md5($data['folder_path'] . time());
            $mapping_result = $this->db->add_or_update_folder_mapping(
                $data['folder_id'], 
                $data['folder_path'], 
                $sync_hash
            );
            
            if (!$mapping_result) {
                throw new Exception("Failed to update folder mapping in database");
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("Folder created in Dropbox successfully", [
                'folder_id' => $data['folder_id'],
                'folder_path' => $data['folder_path'],
                'folder_name' => isset($data['folder_name']) ? $data['folder_name'] : basename($data['folder_path']),
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Folder create task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'folder_id' => isset($data['folder_id']) ? $data['folder_id'] : 'unknown',
                'folder_path' => isset($data['folder_path']) ? $data['folder_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Process folder rename task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_folder_rename_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['old_path']) || empty($data['new_path'])) {
                throw new Exception("Missing path information in folder rename task");
            }
            
            if (empty($data['folder_id']) || !is_numeric($data['folder_id'])) {
                throw new Exception("Missing or invalid folder ID");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Move (rename) the folder in Dropbox
            $result = $this->dropbox_api->move_folder($data['old_path'], $data['new_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API rename folder failed");
            }
            
            global $wpdb;
            
            // Start transaction for database operations
            $wpdb->query('START TRANSACTION');
            
            try {
                // Update folder mapping
                $sync_hash = md5($data['new_path'] . time());
                $mapping_result = $this->db->add_or_update_folder_mapping(
                    $data['folder_id'], 
                    $data['new_path'], 
                    $sync_hash
                );
                
                if (!$mapping_result) {
                    throw new Exception("Failed to update folder mapping in database");
                }
                
                // Update child file mappings to use the new path
                $this->update_child_file_paths($data['old_path'], $data['new_path']);
                
                // Commit transaction
                $wpdb->query('COMMIT');
            } catch (Exception $inner_exception) {
                // Rollback transaction on database error
                $wpdb->query('ROLLBACK');
                throw new Exception("Database operation failed: " . $inner_exception->getMessage());
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("Folder renamed in Dropbox successfully", [
                'folder_id' => $data['folder_id'],
                'old_path' => $data['old_path'],
                'new_path' => $data['new_path'],
                'new_name' => isset($data['new_name']) ? $data['new_name'] : basename($data['new_path']),
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Folder rename task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'folder_id' => isset($data['folder_id']) ? $data['folder_id'] : 'unknown',
                'old_path' => isset($data['old_path']) ? $data['old_path'] : 'unknown',
                'new_path' => isset($data['new_path']) ? $data['new_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Process folder delete task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_folder_delete_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['folder_path'])) {
                throw new Exception("Missing folder path in folder delete task");
            }
            
            if (empty($data['folder_id']) || !is_numeric($data['folder_id'])) {
                throw new Exception("Missing or invalid folder ID");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Delete the folder in Dropbox
            $result = $this->dropbox_api->delete_folder($data['folder_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API delete folder failed");
            }
            
            global $wpdb;
            
            // Start transaction for database operations
            $wpdb->query('START TRANSACTION');
            
            try {
                // Delete mapping
                $mapping_result = $this->db->delete_folder_mapping_by_folder_id($data['folder_id']);
                
                if ($mapping_result === false) {
                    throw new Exception("Failed to delete folder mapping from database");
                }
                
                // Delete related file mappings
                $this->delete_child_file_mappings($data['folder_path']);
                
                // Commit transaction
                $wpdb->query('COMMIT');
            } catch (Exception $inner_exception) {
                // Rollback transaction on database error
                $wpdb->query('ROLLBACK');
                throw new Exception("Database operation failed: " . $inner_exception->getMessage());
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("Folder deleted from Dropbox successfully", [
                'folder_id' => $data['folder_id'],
                'folder_path' => $data['folder_path'],
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Folder delete task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'folder_id' => isset($data['folder_id']) ? $data['folder_id'] : 'unknown',
                'folder_path' => isset($data['folder_path']) ? $data['folder_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Process folder move task.
     *
     * @since    1.0.0
     * @param    object    $task    The task object.
     * @return   boolean            True on success, false on failure.
     */
    public function process_folder_move_task($task) {
        try {
            $data = maybe_unserialize($task->data);
            
            // Validate input data
            if (empty($data['old_path']) || empty($data['new_path'])) {
                throw new Exception("Missing path information in folder move task");
            }
            
            if (empty($data['folder_id']) || !is_numeric($data['folder_id'])) {
                throw new Exception("Missing or invalid folder ID");
            }
            
            // Track operation timing
            $start_time = microtime(true);
            
            // Move the folder in Dropbox
            $result = $this->dropbox_api->move_folder($data['old_path'], $data['new_path']);
            
            if (!$result) {
                throw new Exception("Dropbox API move folder failed");
            }
            
            global $wpdb;
            
            // Start transaction for database operations
            $wpdb->query('START TRANSACTION');
            
            try {
                // Update mapping
                $sync_hash = md5($data['new_path'] . time());
                $mapping_result = $this->db->add_or_update_folder_mapping(
                    $data['folder_id'], 
                    $data['new_path'], 
                    $sync_hash
                );
                
                if (!$mapping_result) {
                    throw new Exception("Failed to update folder mapping in database");
                }
                
                // Update child file mappings to use the new path
                $this->update_child_file_paths($data['old_path'], $data['new_path']);
                
                // Commit transaction
                $wpdb->query('COMMIT');
            } catch (Exception $inner_exception) {
                // Rollback transaction on database error
                $wpdb->query('ROLLBACK');
                throw new Exception("Database operation failed: " . $inner_exception->getMessage());
            }
            
            // Log success with timing
            $elapsed = microtime(true) - $start_time;
            $this->logger->info("Folder moved in Dropbox successfully", [
                'folder_id' => $data['folder_id'],
                'old_path' => $data['old_path'],
                'new_path' => $data['new_path'],
                'new_parent_id' => isset($data['new_parent_id']) ? $data['new_parent_id'] : 'unknown',
                'elapsed_seconds' => round($elapsed, 2)
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Folder move task failed", [
                'exception' => $e->getMessage(),
                'task_id' => $task->id,
                'folder_id' => isset($data['folder_id']) ? $data['folder_id'] : 'unknown',
                'old_path' => isset($data['old_path']) ? $data['old_path'] : 'unknown',
                'new_path' => isset($data['new_path']) ? $data['new_path'] : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Create a folder in FileBird from Dropbox folder.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox folder path.
     * @param    int       $parent_id       The parent folder ID in FileBird.
     * @return   int|false                 The new folder ID or false on failure.
     */
    public function create_filebird_folder_from_dropbox($dropbox_path, $parent_id = 0) {
        $folder_name = basename($dropbox_path);
        
        // Skip hidden folders
        if (substr($folder_name, 0, 1) === '.') {
            $this->logger->info("Skipping hidden folder", array(
                'dropbox_path' => $dropbox_path
            ));
            return false;
        }
        
        // Check if folder already exists in database mapping
        $existing_mapping = $this->db->get_folder_mapping_by_dropbox_path($dropbox_path);
        if ($existing_mapping) {
            return $existing_mapping->filebird_folder_id;
        }
        
        // Use FileBird API to create a new folder
        try {
            if (!class_exists('FileBird\\Model\\Folder')) {
                $this->logger->error("FileBird Folder model not available");
                return false;
            }
            
            $result = \FileBird\Model\Folder::newOrGet($folder_name, $parent_id, false);
            
            if ($result === false) {
                $this->logger->error("Folder already exists with same name", array(
                    'folder_name' => $folder_name,
                    'parent_id' => $parent_id
                ));
                return false;
            }
            
            if (isset($result['id'])) {
                $folder_id = $result['id'];
                
                // Add mapping
                $sync_hash = md5($dropbox_path . time());
                $this->db->add_or_update_folder_mapping($folder_id, $dropbox_path, $sync_hash);
                
                $this->logger->info("Created FileBird folder from Dropbox", array(
                    'folder_id' => $folder_id,
                    'folder_name' => $folder_name,
                    'parent_id' => $parent_id,
                    'dropbox_path' => $dropbox_path
                ));
                
                return $folder_id;
            }
        } catch (Exception $e) {
            $this->logger->error("Error creating FileBird folder", array(
                'exception' => $e->getMessage(),
                'folder_name' => $folder_name,
                'parent_id' => $parent_id
            ));
        }
        
        return false;
    }

    /**
     * Rename a folder in FileBird from Dropbox folder.
     *
     * @since    1.0.0
     * @param    int       $folder_id     The folder ID in FileBird.
     * @param    string    $new_name      The new folder name.
     * @return   boolean                  True on success, false on failure.
     */
    public function rename_filebird_folder($folder_id, $new_name) {
        try {
            if (!class_exists('FileBird\\Model\\Folder')) {
                $this->logger->error("FileBird Folder model not available");
                return false;
            }
            
            // Get folder details
            $folder = \FileBird\Model\Folder::findById($folder_id, 'id, parent');
            
            if (!$folder) {
                $this->logger->error("Folder not found for renaming", array(
                    'folder_id' => $folder_id
                ));
                return false;
            }
            
            $parent_id = $folder->parent;
            
            $result = \FileBird\Model\Folder::updateFolderName($new_name, $parent_id, $folder_id);
            
            if ($result === true) {
                $this->logger->info("Renamed FileBird folder", array(
                    'folder_id' => $folder_id,
                    'new_name' => $new_name
                ));
                return true;
            } else {
                $this->logger->error("Error renaming FileBird folder", array(
                    'folder_id' => $folder_id,
                    'new_name' => $new_name,
                    'result' => $result
                ));
                return false;
            }
        } catch (Exception $e) {
            $this->logger->error("Exception renaming FileBird folder", array(
                'exception' => $e->getMessage(),
                'folder_id' => $folder_id,
                'new_name' => $new_name
            ));
            return false;
        }
    }

    /**
     * Delete a folder in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID in FileBird.
     * @return   boolean                True on success, false on failure.
     */
    public function delete_filebird_folder($folder_id) {
        try {
            if (!class_exists('FileBird\\Model\\Folder')) {
                $this->logger->error("FileBird Folder model not available");
                return false;
            }
            
            \FileBird\Model\Folder::deleteFolderAndItsChildren($folder_id);
            
            // Delete mapping
            $this->db->delete_folder_mapping_by_folder_id($folder_id);
            
            $this->logger->info("Deleted FileBird folder", array(
                'folder_id' => $folder_id
            ));
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Exception deleting FileBird folder", array(
                'exception' => $e->getMessage(),
                'folder_id' => $folder_id
            ));
            return false;
        }
    }

    /**
     * Move a folder in FileBird.
     *
     * @since    1.0.0
     * @param    int       $folder_id       The folder ID in FileBird.
     * @param    int       $new_parent_id   The new parent folder ID.
     * @return   boolean                    True on success, false on failure.
     */
    public function move_filebird_folder($folder_id, $new_parent_id) {
        try {
            if (!class_exists('FileBird\\Model\\Folder')) {
                $this->logger->error("FileBird Folder model not available");
                return false;
            }
            
            \FileBird\Model\Folder::updateParent($folder_id, $new_parent_id);
            
            $this->logger->info("Moved FileBird folder", array(
                'folder_id' => $folder_id,
                'new_parent_id' => $new_parent_id
            ));
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Exception moving FileBird folder", array(
                'exception' => $e->getMessage(),
                'folder_id' => $folder_id,
                'new_parent_id' => $new_parent_id
            ));
            return false;
        }
    }

    /**
     * Get the Dropbox path corresponding to a FileBird folder.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The FileBird folder ID.
     * @return   string                 The Dropbox path.
     */
    public function get_dropbox_path_for_filebird_folder($folder_id) {
        // Check if we already have a mapping
        $mapping = $this->db->get_folder_mapping_by_folder_id($folder_id);
        if ($mapping) {
            return $mapping->dropbox_path;
        }
        
        // If not, we need to build the path
        if (!class_exists('FileBird\\Model\\Folder')) {
            $this->logger->error("FileBird Folder model not available");
            return false;
        }
        
        $folder = \FileBird\Model\Folder::findById($folder_id, 'id, name, parent');
        
        if (!$folder) {
            $this->logger->error("Folder not found", array(
                'folder_id' => $folder_id
            ));
            return false;
        }
        
        $folder_name = sanitize_file_name($folder->name);
        $parent_id = $folder->parent;
        
        if ($parent_id === 0) {
            // This is a top-level folder
            $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
            return $root_folder . '/' . $folder_name;
        } else {
            // This is a child folder, get parent path
            $parent_path = $this->get_dropbox_path_for_filebird_folder($parent_id);
            
            if (!$parent_path) {
                $this->logger->error("Failed to get parent path", array(
                    'folder_id' => $folder_id,
                    'parent_id' => $parent_id
                ));
                return false;
            }
            
            return $parent_path . '/' . $folder_name;
        }
    }

    /**
     * Get the FileBird folder ID corresponding to a Dropbox path.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox path.
     * @return   int|false                 The FileBird folder ID or false if not found.
     */
    public function get_filebird_folder_id_for_dropbox_path($dropbox_path) {
        // Check if we already have a mapping
        $mapping = $this->db->get_folder_mapping_by_dropbox_path($dropbox_path);
        if ($mapping) {
            return $mapping->filebird_folder_id;
        }
        
        // If not, try to find the parent path and create this folder
        $parent_path = dirname($dropbox_path);
        $folder_name = basename($dropbox_path);
        
        // Skip if this is a hidden folder
        if (substr($folder_name, 0, 1) === '.') {
            return false;
        }
        
        $root_folder = get_option('fds_root_dropbox_folder', FDS_ROOT_DROPBOX_FOLDER);
        
        if ($parent_path === '.' || $parent_path === $root_folder) {
            // This is a top-level folder
            $parent_id = 0;
        } else {
            // This is a child folder, get parent ID
            $parent_id = $this->get_filebird_folder_id_for_dropbox_path($parent_path);
            
            if (!$parent_id) {
                $this->logger->error("Failed to get parent folder ID", array(
                    'dropbox_path' => $dropbox_path,
                    'parent_path' => $parent_path
                ));
                return false;
            }
        }
        
        // Create the folder
        return $this->create_filebird_folder_from_dropbox($dropbox_path, $parent_id);
    }

    /**
     * Update file paths for all child files when a folder is moved or renamed.
     *
     * @since    1.0.0
     * @param    string    $old_path    The old folder path.
     * @param    string    $new_path    The new folder path.
     */
    protected function update_child_file_paths($old_path, $new_path) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
        // Get all file mappings that start with the old path
        $old_path_like = $old_path . '/%';
        
        $file_mappings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE dropbox_path LIKE %s",
                $old_path_like
            )
        );
        
        foreach ($file_mappings as $mapping) {
            $new_file_path = str_replace($old_path, $new_path, $mapping->dropbox_path);
            
            $wpdb->update(
                $table_name,
                array('dropbox_path' => $new_file_path),
                array('id' => $mapping->id),
                array('%s'),
                array('%d')
            );
            
            $this->logger->debug("Updated child file path", array(
                'attachment_id' => $mapping->attachment_id,
                'old_path' => $mapping->dropbox_path,
                'new_path' => $new_file_path
            ));
        }
    }

    /**
     * Delete file mappings for all files in a folder when the folder is deleted.
     *
     * @since    1.0.0
     * @param    string    $folder_path    The folder path.
     */
    protected function delete_child_file_mappings($folder_path) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fds_file_mapping';
        
        // Get all file mappings that start with the folder path
        $folder_path_like = $folder_path . '/%';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE dropbox_path LIKE %s",
                $folder_path_like
            )
        );
        
        $this->logger->debug("Deleted child file mappings", array(
            'folder_path' => $folder_path
        ));
    }
}