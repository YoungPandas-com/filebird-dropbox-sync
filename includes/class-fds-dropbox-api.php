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
     * The settings instance.
     *
     * @var FDS_Settings
     */
    protected $settings;

    /**
     * The logger instance.
     *
     * @var FDS_Logger
     */
    protected $logger;

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
    public function __construct($settings, $logger = null) {
        $this->settings = $settings;
        $this->logger = $logger ?: new FDS_Logger();
        $this->request_window_start = time();
    }
    
    /**
     * Upload a file to Dropbox.
     *
     * @param string $local_path Local file path.
     * @param string $dropbox_path Dropbox destination path.
     * @param bool $overwrite Whether to overwrite existing file.
     * @return array|false File metadata or false on failure.
     */
    public function upload_file($local_path, $dropbox_path, $overwrite = true) {
        // Check if file exists
        if (!file_exists($local_path)) {
            $this->logger->error("File not found for upload", [
                'local_path' => $local_path,
                'dropbox_path' => $dropbox_path
            ]);
            return false;
        }
        
        // Check file size and decide on upload method
        $file_size = filesize($local_path);
        if ($file_size > 8388608) { // 8MB
            return $this->upload_file_chunked($local_path, $dropbox_path);
        }
        
        try {
            // Prepare API request
            $url = 'https://content.dropboxapi.com/2/files/upload';
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . get_option('fds_dropbox_access_token', ''),
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => json_encode([
                        'path' => $dropbox_path,
                        'mode' => $overwrite ? 'overwrite' : 'add',
                        'autorename' => false,
                        'mute' => false
                    ])
                ],
                'body' => file_get_contents($local_path),
                'timeout' => 60,
            ];
            
            // Track timing
            $start_time = microtime(true);
            
            // Execute request with retry logic
            $max_retries = 3;
            $retry_count = 0;
            
            while ($retry_count < $max_retries) {
                $response = wp_remote_request($url, $args);
                $this->request_count++;
                
                if (!is_wp_error($response)) {
                    $status_code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    
                    if ($status_code === 200) {
                        $result = json_decode($body, true);
                        
                        // Log success
                        $elapsed = microtime(true) - $start_time;
                        $this->logger->info("File uploaded successfully", [
                            'dropbox_path' => $dropbox_path,
                            'size' => $file_size,
                            'elapsed_seconds' => round($elapsed, 2)
                        ]);
                        
                        return $result;
                    } elseif ($status_code === 429) {
                        // Rate limited
                        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                        $retry_after = $retry_after ? intval($retry_after) : 10;
                        
                        $this->logger->warning("Rate limited during upload", [
                            'retry_after' => $retry_after,
                            'retry_count' => $retry_count
                        ]);
                        
                        sleep($retry_after);
                        $retry_count++;
                        continue;
                    } elseif ($status_code === 401) {
                        // Unauthorized - try to refresh token
                        $this->logger->warning("Unauthorized during upload, refreshing token", [
                            'retry_count' => $retry_count
                        ]);
                        
                        if ($this->refresh_access_token()) {
                            $args['headers']['Authorization'] = 'Bearer ' . get_option('fds_dropbox_access_token', '');
                            $retry_count++;
                            continue;
                        }
                        
                        throw new Exception("Unauthorized and token refresh failed");
                    } else {
                        // Other error
                        throw new Exception("Upload failed with status {$status_code}: {$body}");
                    }
                } else {
                    // Network error
                    $this->logger->warning("Network error during upload", [
                        'error' => $response->get_error_message(),
                        'retry_count' => $retry_count
                    ]);
                    
                    // Exponential backoff
                    sleep(pow(2, $retry_count));
                    $retry_count++;
                    continue;
                }
            }
            
            throw new Exception("Upload failed after {$max_retries} retries");
        } catch (Exception $e) {
            $this->logger->error("File upload failed", [
                'exception' => $e->getMessage(),
                'local_path' => $local_path,
                'dropbox_path' => $dropbox_path
            ]);
            
            return false;
        }
    }
    
    /**
     * Get file metadata from Dropbox.
     *
     * @param string $path Dropbox file path.
     * @return array|false Metadata or false on failure.
     */
    public function get_file_metadata($path) {
        try {
            $params = ['path' => $path];
            $result = $this->make_api_request('files/get_metadata', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to get file metadata", [
                'exception' => $e->getMessage(),
                'path' => $path
            ]);
            
            return false;
        }
    }
    
    /**
     * Download a file from Dropbox.
     *
     * @param string $dropbox_path Dropbox file path.
     * @param string $local_path Local destination path.
     * @return bool True on success, false on failure.
     */
    public function download_file($dropbox_path, $local_path) {
        return $this->download_file_enhanced($dropbox_path, $local_path);
    }
    
    /**
     * Delete a file from Dropbox.
     *
     * @param string $path Dropbox file path.
     * @return bool True on success, false on failure.
     */
    public function delete_file($path) {
        try {
            $params = ['path' => $path];
            $result = $this->make_api_request('files/delete_v2', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            $this->logger->info("File deleted from Dropbox", [
                'path' => $path
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to delete file", [
                'exception' => $e->getMessage(),
                'path' => $path
            ]);
            
            return false;
        }
    }
    
    /**
     * Move or rename a file in Dropbox.
     *
     * @param string $from_path Source path.
     * @param string $to_path Destination path.
     * @return bool True on success, false on failure.
     */
    public function move_file($from_path, $to_path) {
        try {
            $params = [
                'from_path' => $from_path,
                'to_path' => $to_path,
                'allow_shared_folder' => false,
                'autorename' => false,
                'allow_ownership_transfer' => false
            ];
            
            $result = $this->make_api_request('files/move_v2', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            $this->logger->info("File moved in Dropbox", [
                'from_path' => $from_path,
                'to_path' => $to_path
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to move file", [
                'exception' => $e->getMessage(),
                'from_path' => $from_path,
                'to_path' => $to_path
            ]);
            
            return false;
        }
    }
    
    /**
     * Create a folder in Dropbox.
     *
     * @param string $path Dropbox folder path.
     * @return bool True on success, false on failure.
     */
    public function create_folder($path) {
        try {
            $params = [
                'path' => $path,
                'autorename' => false
            ];
            
            $result = $this->make_api_request('files/create_folder_v2', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            $this->logger->info("Folder created in Dropbox", [
                'path' => $path
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to create folder", [
                'exception' => $e->getMessage(),
                'path' => $path
            ]);
            
            return false;
        }
    }
    
    /**
     * Delete a folder from Dropbox.
     *
     * @param string $path Dropbox folder path.
     * @return bool True on success, false on failure.
     */
    public function delete_folder($path) {
        return $this->delete_file($path); // Uses the same API endpoint
    }
    
    /**
     * Move or rename a folder in Dropbox.
     *
     * @param string $from_path Source path.
     * @param string $to_path Destination path.
     * @return bool True on success, false on failure.
     */
    public function move_folder($from_path, $to_path) {
        return $this->move_file($from_path, $to_path); // Uses the same API endpoint
    }
    
    /**
     * Get folder cursor for delta sync.
     *
     * @param string $path Dropbox folder path.
     * @return string|false Cursor or false on failure.
     */
    public function get_folder_cursor($path = '') {
        try {
            $path = empty($path) ? get_option('fds_root_dropbox_folder', '/Website') : $path;
            
            $params = [
                'path' => $path,
                'recursive' => true,
                'include_media_info' => true
            ];
            
            $result = $this->make_api_request('files/list_folder/get_latest_cursor', $params);
            
            if (is_wp_error($result) || !isset($result['cursor'])) {
                throw new Exception(is_wp_error($result) ? $result->get_error_message() : 'No cursor returned');
            }
            
            return $result['cursor'];
        } catch (Exception $e) {
            $this->logger->error("Failed to get folder cursor", [
                'exception' => $e->getMessage(),
                'path' => $path
            ]);
            
            return false;
        }
    }
    
    /**
     * Get the latest cursor from storage.
     *
     * @return string|false Cursor or false if not found.
     */
    public function get_latest_cursor() {
        return get_option('fds_dropbox_cursor', false);
    }
    
    /**
     * Get changes since the last sync.
     *
     * @param string $cursor Previous cursor.
     * @return array|false Changes or false on failure.
     */
    public function get_changes($cursor) {
        if (empty($cursor)) {
            return false;
        }
        
        try {
            $result = $this->list_folder_continue($cursor);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to get changes", [
                'exception' => $e->getMessage(),
                'cursor' => $cursor
            ]);
            
            return false;
        }
    }
    
    /**
     * Refresh the access token using the refresh token.
     *
     * @return bool True if token was refreshed, false otherwise.
     */
    public function refresh_access_token() {
        $refresh_token = get_option('fds_dropbox_refresh_token', '');
        
        if (empty($refresh_token)) {
            return false;
        }
        
        try {
            $url = 'https://api.dropboxapi.com/oauth2/token';
            $app_key = get_option('fds_dropbox_app_key', '');
            $app_secret = get_option('fds_dropbox_app_secret', '');
            
            if (empty($app_key) || empty($app_secret)) {
                throw new Exception("App key or secret not configured");
            }
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'client_id' => $app_key,
                    'client_secret' => $app_secret
                ]
            ];
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);
            
            if ($status_code !== 200 || !isset($result['access_token'])) {
                throw new Exception("Failed to refresh token: " . ($result['error_description'] ?? $body));
            }
            
            // Save the new token
            update_option('fds_dropbox_access_token', $result['access_token']);
            
            // Update expiry time if provided
            if (isset($result['expires_in'])) {
                update_option('fds_dropbox_token_expiry', time() + $result['expires_in']);
            }
            
            $this->logger->info("Dropbox access token refreshed successfully");
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to refresh access token", [
                'exception' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Check if there is a valid token.
     *
     * @return bool True if token is valid, false otherwise.
     */
    public function has_valid_token() {
        $access_token = get_option('fds_dropbox_access_token', '');
        $token_expiry = get_option('fds_dropbox_token_expiry', 0);
        
        if (empty($access_token)) {
            return false;
        }
        
        // If token has an expiry and it's expired, try to refresh
        if ($token_expiry > 0 && $token_expiry <= time()) {
            return $this->refresh_access_token();
        }
        
        return true;
    }
    
    /**
     * Register a webhook with Dropbox.
     *
     * @return bool True on success, false on failure.
     */
    public function register_webhook() {
        try {
            $webhook_url = get_rest_url(null, 'fds/v1/webhook');
            
            $params = [
                'list_folder' => [
                    'path' => get_option('fds_root_dropbox_folder', '/Website')
                ],
                'url' => $webhook_url
            ];
            
            $result = $this->make_api_request('files/list_folder/longpoll', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            $this->logger->info("Webhook registered with Dropbox", [
                'webhook_url' => $webhook_url
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to register webhook", [
                'exception' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Unregister a webhook with Dropbox.
     *
     * @return bool True on success, false on failure.
     */
    public function unregister_webhook() {
        try {
            $webhook_url = get_rest_url(null, 'fds/v1/webhook');
            
            $params = [
                'url' => $webhook_url
            ];
            
            $result = $this->make_api_request('files/list_folder/longpoll/stop', $params);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            $this->logger->info("Webhook unregistered from Dropbox", [
                'webhook_url' => $webhook_url
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to unregister webhook", [
                'exception' => $e->getMessage()
            ]);
            
            return false;
        }
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
    
    /**
     * Handle AJAX for OAuth start.
     * 
     * @since    1.0.0
     */
    public function ajax_oauth_start() {
        check_ajax_referer('fds-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) { // Changed from 'manage_fds_settings' to 'manage_options'
            wp_send_json_error(['message' => __('Permission denied.', 'filebird-dropbox-sync')]);
        }
        
        // Generate CSRF token for OAuth
        $csrf_token = wp_generate_password(32, false);
        update_option('fds_oauth_csrf_token', $csrf_token);
        
        // Get app key
        $app_key = get_option('fds_dropbox_app_key', '');
        
        if (empty($app_key)) {
            wp_send_json_error(['message' => __('Dropbox App Key is not configured. Please enter your App Key in the settings before connecting.', 'filebird-dropbox-sync')]);
        }
        
        // Prepare redirect URL
        $redirect_url = admin_url('admin-ajax.php') . '?action=fds_oauth_finish';
        
        // Build authorization URL
        $auth_url = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $app_key,
            'response_type' => 'code',
            'redirect_uri' => $redirect_url,
            'state' => $csrf_token,
            'token_access_type' => 'offline',
        ]);
        
        wp_send_json_success(['auth_url' => $auth_url]);
    }

    /**
     * Handle AJAX for OAuth finish.
     * 
     * @since    1.0.0
     */
    public function ajax_oauth_finish() {
        // Verify CSRF
        $stored_csrf = get_option('fds_oauth_csrf_token', '');
        $received_csrf = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        
        if (empty($stored_csrf) || $stored_csrf !== $received_csrf) {
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&error=csrf'));
            exit;
        }
        
        // Get authorization code
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        
        if (empty($code)) {
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&error=code'));
            exit;
        }
        
        // Exchange code for token
        $app_key = get_option('fds_dropbox_app_key', '');
        $app_secret = get_option('fds_dropbox_app_secret', '');
        $redirect_url = admin_url('admin-ajax.php') . '?action=fds_oauth_finish';
        
        $response = wp_remote_post('https://api.dropboxapi.com/oauth2/token', [
            'body' => [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $app_key,
                'client_secret' => $app_secret,
                'redirect_uri' => $redirect_url,
            ],
            'timeout' => 30, // Increase timeout
        ]);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('FileBird Dropbox Sync - OAuth error: ' . $error_message);
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&error=request&message=' . urlencode($error_message)));
            exit;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || isset($data['error'])) {
            $error_msg = isset($data['error_description']) ? $data['error_description'] : 'Unknown error';
            error_log('FileBird Dropbox Sync - OAuth token error: ' . $error_msg);
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&error=api&message=' . urlencode($error_msg)));
            exit;
        }
        
        // Store tokens
        if (isset($data['access_token'])) {
            update_option('fds_dropbox_access_token', $data['access_token']);
            
            if (isset($data['refresh_token'])) {
                update_option('fds_dropbox_refresh_token', $data['refresh_token']);
            }
            
            if (isset($data['expires_in'])) {
                update_option('fds_dropbox_token_expiry', time() + $data['expires_in']);
            }
            
            // Don't register webhook immediately, it might fail
            // Let's just redirect and show success message
            
            // Clear CSRF token
            delete_option('fds_oauth_csrf_token');
            
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&connected=1'));
            exit;
        } else {
            wp_redirect(admin_url('admin.php?page=filebird-dropbox-sync-settings&tab=dropbox&error=token'));
            exit;
        }
    }
}