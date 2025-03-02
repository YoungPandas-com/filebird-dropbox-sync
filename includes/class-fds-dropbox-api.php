<?php
/**
 * Enhanced Dropbox API integration for high-volume operations.
 *
 * Provides optimized methods for handling Dropbox API operations at scale.
 *
 * @since      1.0.0
 */
class FDS_Dropbox_API {

    /**
     * API rate limiting parameters.
     */
    protected $rate_limit_remaining = 1000;
    protected $rate_limit_reset = 0;
    protected $request_count = 0;
    protected $request_window_start = 0;
    protected $max_requests_per_window = 100; // 100 requests per 60 seconds (conservative)
    protected $request_window_seconds = 60;
    
    /**
     * Chunked upload state.
     */
    protected $active_sessions = [];
    
    /**
     * Caching TTL in seconds.
     */
    protected $cache_ttl = 300; // 5 minutes
    
    /**
     * Constructor with enhanced initialization.
     *
     * @param FDS_Settings $settings Settings instance.
     * @param FDS_Logger $logger Logger instance.
     */
    public function __construct($settings, $logger) {
        parent::__construct($settings);
        $this->logger = $logger;
        $this->request_window_start = time();
    }
    
    /**
     * Make an API request with rate limiting and backoff.
     *
     * @param string $endpoint API endpoint.
     * @param array $params Request parameters.
     * @param string $method HTTP method (GET/POST).
     * @param array $headers Additional headers.
     * @return array|WP_Error Response or error.
     */
    protected function make_api_request($endpoint, $params = [], $method = 'POST', $headers = []) {
        // Check rate limiting
        $this->check_rate_limits();
        
        // Prepare request
        $url = 'https://api.dropboxapi.com/2/' . ltrim($endpoint, '/');
        
        $default_headers = [
            'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
            'Content-Type' => 'application/json',
        ];
        
        $headers = array_merge($default_headers, $headers);
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        if (!empty($params) && $method === 'POST') {
            $args['body'] = json_encode($params);
        }
        
        // Execute request with retry logic
        $max_retries = 3;
        $retry_count = 0;
        
        while ($retry_count < $max_retries) {
            $response = wp_remote_request($url, $args);
            $this->request_count++;
            
            // Check for success
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                // Store rate limit headers
                $this->parse_rate_limit_headers($response);
                
                // Handle specific status codes
                if ($status_code === 200) {
                    // Success
                    return json_decode($body, true);
                } elseif ($status_code === 429) {
                    // Rate limited - get retry after value
                    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                    $retry_after = $retry_after ? intval($retry_after) : 10;
                    
                    $this->logger->warning("Rate limited by Dropbox API", [
                        'endpoint' => $endpoint,
                        'retry_after' => $retry_after,
                        'status_code' => $status_code
                    ]);
                    
                    // Sleep and retry
                    sleep($retry_after);
                    $retry_count++;
                    continue;
                } elseif ($status_code === 401) {
                    // Unauthorized - token expired?
                    $this->logger->error("Unauthorized API request", [
                        'endpoint' => $endpoint,
                        'status_code' => $status_code,
                        'body' => $body
                    ]);
                    
                    // Try to refresh token
                    if ($this->refresh_access_token()) {
                        // Update authorization header with new token
                        $args['headers']['Authorization'] = 'Bearer ' . get_option('fds_dropbox_access_token', '');
                        $retry_count++;
                        continue;
                    }
                    
                    return new WP_Error('api_unauthorized', 'Unauthorized API request');
                } elseif ($status_code >= 500) {
                    // Server error - retry with backoff
                    $this->logger->warning("Dropbox server error", [
                        'endpoint' => $endpoint,
                        'status_code' => $status_code,
                        'retry_count' => $retry_count
                    ]);
                    
                    // Exponential backoff
                    sleep(pow(2, $retry_count));
                    $retry_count++;
                    continue;
                } else {
                    // Other error
                    $this->logger->error("API request failed", [
                        'endpoint' => $endpoint,
                        'status_code' => $status_code,
                        'body' => $body
                    ]);
                    
                    return new WP_Error('api_error', $body);
                }
            } else {
                // Network error
                $this->logger->error("Network error", [
                    'endpoint' => $endpoint,
                    'error' => $response->get_error_message()
                ]);
                
                // Retry network errors with backoff
                sleep(pow(2, $retry_count));
                $retry_count++;
                continue;
            }
        }
        
        // All retries failed
        return new WP_Error('max_retries', 'Maximum retries reached');
    }
    
    /**
     * Start an upload session for chunked file uploads.
     *
     * @param string $chunk First chunk data.
     * @return string|WP_Error Session ID or error.
     */
    public function start_upload_session($chunk) {
        $url = 'https://content.dropboxapi.com/2/files/upload_session/start';
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode(['close' => false])
            ],
            'body' => $chunk,
            'timeout' => 60,
        ];
        
        $response = wp_remote_request($url, $args);
        $this->request_count++;
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            return new WP_Error('session_start_error', $body);
        }
        
        $result = json_decode($body, true);
        
        if (isset($result['session_id'])) {
            // Track active session
            $this->active_sessions[$result['session_id']] = [
                'offset' => strlen($chunk),
                'started_at' => time()
            ];
            
            return $result['session_id'];
        }
        
        return new WP_Error('missing_session_id', 'No session ID in response');
    }
    
    /**
     * Append data to an upload session.
     *
     * @param string $session_id Session ID.
     * @param string $chunk Chunk data.
     * @param int $offset Current offset.
     * @return bool|WP_Error True on success or error.
     */
    public function append_to_upload_session($session_id, $chunk, $offset) {
        $url = 'https://content.dropboxapi.com/2/files/upload_session/append_v2';
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset' => $offset
                    ],
                    'close' => false
                ])
            ],
            'body' => $chunk,
            'timeout' => 60,
        ];
        
        $response = wp_remote_request($url, $args);
        $this->request_count++;
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('append_error', $body);
        }
        
        // Update session tracking
        if (isset($this->active_sessions[$session_id])) {
            $this->active_sessions[$session_id]['offset'] += strlen($chunk);
        }
        
        return true;
    }
    
    /**
     * Finish an upload session.
     *
     * @param string $session_id Session ID.
     * @param string $path Destination path.
     * @param int $total_size Total file size.
     * @return array|WP_Error File metadata or error.
     */
    public function finish_upload_session($session_id, $path, $total_size) {
        $url = 'https://content.dropboxapi.com/2/files/upload_session/finish';
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset' => $total_size
                    ],
                    'commit' => [
                        'path' => $path,
                        'mode' => 'overwrite',
                        'autorename' => false,
                        'mute' => false
                    ]
                ])
            ],
            'body' => '',
            'timeout' => 120, // Longer timeout for finishing large uploads
        ];
        
        $response = wp_remote_request($url, $args);
        $this->request_count++;
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            return new WP_Error('finish_error', $body);
        }
        
        // Remove from active sessions
        if (isset($this->active_sessions[$session_id])) {
            unset($this->active_sessions[$session_id]);
        }
        
        return json_decode($body, true);
    }
    
    /**
     * Enhanced list folder with cursor-based pagination.
     *
     * @param string $path Folder path.
     * @param array $options Listing options.
     * @return array|WP_Error Entries and cursor or error.
     */
    public function list_folder_enhanced($path, $options = []) {
        $default_options = [
            'recursive' => false,
            'include_deleted' => false,
            'include_media_info' => true,
            'limit' => 1000 // Maximum allowed by API
        ];
        
        $options = array_merge($default_options, $options);
        
        // Check cache first
        $cache_key = 'dropbox_folder_' . md5($path . serialize($options));
        $cached = $this->get_cached_data($cache_key);
        
        if ($cached !== null) {
            return $cached;
        }
        
        // Make API request
        $params = [
            'path' => $path,
            'recursive' => $options['recursive'],
            'include_deleted' => $options['include_deleted'],
            'include_media_info' => $options['include_media_info'],
            'limit' => $options['limit']
        ];
        
        $result = $this->make_api_request('files/list_folder', $params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Cache the result
        $this->set_cached_data($cache_key, $result, $this->cache_ttl);
        
        return $result;
    }
    
    /**
     * Continue listing folder contents with cursor.
     *
     * @param string $cursor Pagination cursor.
     * @return array|WP_Error Entries and cursor or error.
     */
    public function list_folder_continue($cursor) {
        // No caching for continued listings as they're paginated
        $params = ['cursor' => $cursor];
        
        return $this->make_api_request('files/list_folder/continue', $params);
    }
    
    /**
     * Get latest changes with cursor-based delta sync.
     *
     * @param string $cursor Previous cursor.
     * @param string $path_prefix Optional path prefix filter.
     * @return array|WP_Error Changes or error.
     */
    public function get_latest_changes($cursor, $path_prefix = '') {
        $params = ['cursor' => $cursor];
        
        if (!empty($path_prefix)) {
            $params['path_prefix'] = $path_prefix;
        }
        
        return $this->make_api_request('files/list_folder/continue', $params);
    }
    
    /**
     * Upload multiple files in a batch operation.
     *
     * @param array $files Array of [local_path => dropbox_path] mappings.
     * @param callable $progress_callback Optional progress callback.
     * @return array Results for each file.
     */
    public function batch_upload_files($files, $progress_callback = null) {
        $results = [];
        $total = count($files);
        $completed = 0;
        
        foreach ($files as $local_path => $dropbox_path) {
            // Skip if file doesn't exist
            if (!file_exists($local_path)) {
                $results[$local_path] = new WP_Error('file_not_found', 'Local file not found');
                continue;
            }
            
            // Get file size
            $size = filesize($local_path);
            
            if ($size <= 8388608) { // 8MB - standard upload
                $result = $this->upload_file($local_path, $dropbox_path, true);
            } else { // Large file - chunked upload
                $result = $this->upload_file_chunked($local_path, $dropbox_path);
            }
            
            $results[$local_path] = $result;
            $completed++;
            
            // Call progress callback if provided
            if (is_callable($progress_callback)) {
                call_user_func($progress_callback, $completed, $total, $local_path, $result);
            }
        }
        
        return $results;
    }
    
    /**
     * Upload a file using chunked upload for large files.
     *
     * @param string $local_path Local file path.
     * @param string $dropbox_path Dropbox destination path.
     * @param int $chunk_size Chunk size in bytes.
     * @return array|WP_Error File metadata or error.
     */
    public function upload_file_chunked($local_path, $dropbox_path, $chunk_size = null) {
        if (!file_exists($local_path)) {
            return new WP_Error('file_not_found', 'Local file not found');
        }
        
        if ($chunk_size === null) {
            $chunk_size = get_option('fds_chunk_size', 8388608); // Default 8MB chunks
        }
        
        $file_size = filesize($local_path);
        $file_handle = fopen($local_path, 'rb');
        
        if (!$file_handle) {
            return new WP_Error('file_open_error', 'Could not open local file');
        }
        
        try {
            // Initialize upload session
            $chunk = fread($file_handle, $chunk_size);
            $session_id = $this->start_upload_session($chunk);
            
            if (is_wp_error($session_id)) {
                throw new Exception($session_id->get_error_message());
            }
            
            $offset = strlen($chunk);
            
            // Upload chunks
            while ($offset < $file_size) {
                $bytes_left = $file_size - $offset;
                $bytes_to_read = min($bytes_left, $chunk_size);
                
                $chunk = fread($file_handle, $bytes_to_read);
                
                if ($chunk === false) {
                    throw new Exception('Failed to read chunk from file');
                }
                
                $result = $this->append_to_upload_session($session_id, $chunk, $offset);
                
                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }
                
                $offset += strlen($chunk);
            }
            
            // Finish upload
            $result = $this->finish_upload_session($session_id, $dropbox_path, $file_size);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            fclose($file_handle);
            return $result;
        } catch (Exception $e) {
            if (is_resource($file_handle)) {
                fclose($file_handle);
            }
            
            $this->logger->error('Chunked upload failed', [
                'exception' => $e->getMessage(),
                'local_path' => $local_path,
                'dropbox_path' => $dropbox_path
            ]);
            
            return new WP_Error('chunked_upload_failed', $e->getMessage());
        }
    }
    
    /**
     * Download a file with progress tracking and integrity verification.
     *
     * @param string $dropbox_path Dropbox file path.
     * @param string $local_path Local destination path.
     * @param callable $progress_callback Optional progress callback.
     * @return bool True on success, false on failure.
     */
    public function download_file_enhanced($dropbox_path, $local_path, $progress_callback = null) {
        // Get file metadata first
        $metadata = $this->get_file_metadata($dropbox_path);
        
        if (!$metadata || is_wp_error($metadata)) {
            $this->logger->error('Failed to get file metadata for download', [
                'dropbox_path' => $dropbox_path
            ]);
            return false;
        }
        
        // Create directory if it doesn't exist
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        // Get temporary file path
        $temp_path = $local_path . '.download';
        
        // Open temp file for writing
        $local_file = fopen($temp_path, 'wb');
        if (!$local_file) {
            $this->logger->error('Failed to open local file for writing', [
                'local_path' => $local_path
            ]);
            return false;
        }
        
        try {
            // Download file
            $url = 'https://content.dropboxapi.com/2/files/download';
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                    'Dropbox-API-Arg' => json_encode(['path' => $dropbox_path])
                ],
                'stream' => true,
                'timeout' => 60,
                'filename' => $temp_path
            ];
            
            $response = wp_remote_request($url, $args);
            $this->request_count++;
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code !== 200) {
                $body = wp_remote_retrieve_body($response);
                throw new Exception('Download failed with status code ' . $status_code . ': ' . $body);
            }
            
            // Close file
            fclose($local_file);
            
            // Verify file size
            $downloaded_size = filesize($temp_path);
            $expected_size = $metadata['size'];
            
            if ($downloaded_size !== $expected_size) {
                throw new Exception("Size mismatch: downloaded {$downloaded_size} bytes but expected {$expected_size} bytes");
            }
            
            // Verify content hash if available
            if (isset($metadata['content_hash'])) {
                $local_hash = $this->calculate_dropbox_content_hash($temp_path);
                
                if ($local_hash !== $metadata['content_hash']) {
                    throw new Exception("Content hash mismatch: downloaded file is corrupted");
                }
            }
            
            // Rename temp file to final destination
            if (!rename($temp_path, $local_path)) {
                throw new Exception("Failed to rename temporary file");
            }
            
            return true;
        } catch (Exception $e) {
            // Clean up
            if (is_resource($local_file)) {
                fclose($local_file);
            }
            
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
            
            $this->logger->error('Enhanced download failed', [
                'exception' => $e->getMessage(),
                'dropbox_path' => $dropbox_path,
                'local_path' => $local_path
            ]);
            
            return false;
        }
    }
    
    /**
     * Calculate Dropbox content hash for a file.
     *
     * @param string $file_path File path.
     * @return string Dropbox content hash.
     */
    protected function calculate_dropbox_content_hash($file_path) {
        $block_size = 4 * 1024 * 1024; // 4MB blocks
        $file = fopen($file_path, 'rb');
        $block_hashes = [];
        
        while (!feof($file)) {
            $block_data = fread($file, $block_size);
            $block_hashes[] = hash('sha256', $block_data, true);
        }
        
        fclose($file);
        
        // Combine all block hashes and hash again
        return hash('sha256', implode('', $block_hashes));
    }
    
    /**
     * Check and handle API rate limits.
     */
    protected function check_rate_limits() {
        // Check if we need to reset the window
        $current_time = time();
        if ($current_time - $this->request_window_start >= $this->request_window_seconds) {
            $this->request_window_start = $current_time;
            $this->request_count = 0;
            return;
        }
        
        // Check if we're approaching rate limit
        if ($this->request_count >= $this->max_requests_per_window) {
            $sleep_time = $this->request_window_seconds - ($current_time - $this->request_window_start) + 1;
            if ($sleep_time > 0) {
                $this->logger->debug("Rate limit approached, sleeping", [
                    'sleep_time' => $sleep_time,
                    'request_count' => $this->request_count
                ]);
                sleep($sleep_time);
            }
            
            $this->request_window_start = time();
            $this->request_count = 0;
        }
    }
    
    /**
     * Parse rate limit headers from API response.
     *
     * @param array $response API response.
     */
    protected function parse_rate_limit_headers($response) {
        $remaining = wp_remote_retrieve_header($response, 'x-dropbox-api-calls-remaining');
        if ($remaining) {
            $this->rate_limit_remaining = intval($remaining);
        }
        
        $reset = wp_remote_retrieve_header($response, 'x-dropbox-api-calls-reset');
        if ($reset) {
            $this->rate_limit_reset = intval($reset);
        }
    }
    
    /**
     * Get cached data or null if not found.
     *
     * @param string $key Cache key.
     * @return mixed|null Cached data or null.
     */
    protected function get_cached_data($key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_cache';
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cache_value FROM $table_name WHERE cache_key = %s AND expires_at > %s",
                $key,
                current_time('mysql')
            )
        );
        
        if ($result) {
            return maybe_unserialize($result->cache_value);
        }
        
        return null;
    }
    
    /**
     * Set data in cache.
     *
     * @param string $key Cache key.
     * @param mixed $value Data to cache.
     * @param int $ttl TTL in seconds.
     * @return bool Success.
     */
    protected function set_cached_data($key, $value, $ttl = 300) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fds_cache';
        
        // Calculate expiration
        $expires_at = date('Y-m-d H:i:s', time() + $ttl);
        
        // Serialize value
        $serialized_value = maybe_serialize($value);
        
        // Delete existing entry
        $wpdb->delete($table_name, ['cache_key' => $key], ['%s']);
        
        // Insert new entry
        $result = $wpdb->insert(
            $table_name,
            [
                'cache_key' => $key,
                'cache_value' => $serialized_value,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
}