/**
 * Admin JavaScript for FileBird Dropbox Sync
 * 
 * Enhanced with better user feedback and error handling
 */
(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        // Track if actions are in progress to prevent duplicate submissions
        let actionInProgress = false;
        
        // Dropbox connection
        initDropboxConnection();
        
        // Manual sync
        initManualSync();
        
        // Logs
        initLogsHandling();
        
        // Initialize sync dashboard
        initSyncDashboard();
        
        // Initialize clipboard functionality
        initClipboard();
        
        // Initialize webhook buttons
        initWebhookButtons();
    });

    /**
     * Initialize Dropbox connection functionality with improved user feedback
     */
    function initDropboxConnection() {
        const $connectButton = $('#fds-connect-dropbox');
        const $disconnectButton = $('#fds-disconnect-dropbox');
        const $statusContainer = $('#fds-connection-status');
        
        if ($connectButton.length) {
            $connectButton.on('click', function() {
                // Prevent multiple clicks
                if (actionInProgress) return;
                
                // Check if app key and secret are entered
                const appKey = $('#fds_dropbox_app_key').val();
                const appSecret = $('#fds_dropbox_app_secret').val();
                
                if (!appKey || !appSecret) {
                    alert('Please enter your Dropbox App Key and App Secret before connecting.');
                    $('#fds_dropbox_app_key').focus();
                    return;
                }
                
                actionInProgress = true;
                $connectButton.prop('disabled', true);
                $connectButton.text('Connecting...');
                
                // Show status message
                $statusContainer.removeClass('hidden error success')
                    .addClass('info')
                    .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> ' + 
                          fds_admin_vars.strings.connecting)
                    .show();
                
                $.ajax({
                    url: fds_admin_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fds_oauth_start',
                        nonce: fds_admin_vars.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.auth_url) {
                            // Show information message to the user
                            $statusContainer.removeClass('info error')
                                .addClass('success')
                                .html('<span class="dashicons dashicons-yes-alt"></span> ' + 
                                      'Dropbox authorization window opened. Please complete the authorization process in the new tab.')
                                .show();
                                
                            // Open Dropbox authorization page in a new window/tab
                            const authWindow = window.open(response.data.auth_url, '_blank');
                            
                            // Check if popup was blocked
                            if (!authWindow || authWindow.closed || typeof authWindow.closed === 'undefined') {
                                $statusContainer.removeClass('info success')
                                    .addClass('error')
                                    .html('<span class="dashicons dashicons-warning"></span> ' + 
                                          'Pop-up blocked! Please allow pop-ups for this site and try again.')
                                    .show();
                            } else {
                                // Inform user to complete authorization
                                const checkAuthInterval = setInterval(function() {
                                    if (authWindow.closed) {
                                        clearInterval(checkAuthInterval);
                                        
                                        // Refresh the page after a brief delay to check connection status
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 2000);
                                    }
                                }, 500);
                            }
                        } else {
                            $statusContainer.removeClass('info success')
                                .addClass('error')
                                .html('<span class="dashicons dashicons-warning"></span> ' + 
                                      fds_admin_vars.strings.error + ' ' + (response.data ? response.data.message : 'Unknown error'))
                                .show();
                        }
                        
                        // Re-enable button
                        $connectButton.prop('disabled', false);
                        $connectButton.text('Connect to Dropbox');
                        actionInProgress = false;
                    },
                    error: function(xhr, status, error) {
                        $statusContainer.removeClass('info success')
                            .addClass('error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + 
                                  fds_admin_vars.strings.error + ' ' + error)
                            .show();
                        
                        // Re-enable button
                        $connectButton.prop('disabled', false);
                        $connectButton.text('Connect to Dropbox');
                        actionInProgress = false;
                    }
                });
            });
        }
        
        if ($disconnectButton.length) {
            $disconnectButton.on('click', function() {
                if (actionInProgress) return;
                
                if (confirm('Are you sure you want to disconnect from Dropbox? This will stop synchronization until you reconnect.')) {
                    actionInProgress = true;
                    $disconnectButton.prop('disabled', true);
                    $disconnectButton.text('Disconnecting...');
                    
                    $statusContainer.removeClass('hidden error success')
                        .addClass('info')
                        .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> ' + 
                              'Disconnecting from Dropbox...')
                        .show();
                    
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_oauth_disconnect',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $statusContainer.removeClass('info error')
                                    .addClass('success')
                                    .html('<span class="dashicons dashicons-yes-alt"></span> ' + 
                                          'Successfully disconnected from Dropbox.');
                                
                                // Reload page after a short delay
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                $statusContainer.removeClass('info success')
                                    .addClass('error')
                                    .html('<span class="dashicons dashicons-warning"></span> ' + 
                                          fds_admin_vars.strings.error + ' ' + (response.data ? response.data.message : 'Unknown error'));
                                
                                // Re-enable button
                                $disconnectButton.prop('disabled', false);
                                $disconnectButton.text('Disconnect from Dropbox');
                                actionInProgress = false;
                            }
                        },
                        error: function(xhr, status, error) {
                            $statusContainer.removeClass('info success')
                                .addClass('error')
                                .html('<span class="dashicons dashicons-warning"></span> ' + 
                                      fds_admin_vars.strings.error + ' ' + error);
                            
                            // Re-enable button
                            $disconnectButton.prop('disabled', false);
                            $disconnectButton.text('Disconnect from Dropbox');
                            actionInProgress = false;
                        }
                    });
                }
            });
        }
    }

/**
 * Initialize clipboard functionality for copy buttons
 */
function initClipboard() {
    $('.copy-webhook-url').on('click', function(e) {
        e.preventDefault();
        
        const text = $(this).data('clipboard-text');
        if (!text) {
            console.error('No text to copy');
            return;
        }
        
        // Use the modern Clipboard API if available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
                .then(() => {
                    const $button = $(this);
                    const originalText = $button.text();
                    $button.text('Copied!');
                    setTimeout(function() {
                        $button.text(originalText);
                    }, 2000);
                })
                .catch(err => {
                    console.error('Failed to copy: ', err);
                    fallbackCopyTextToClipboard(text, this);
                });
        } else {
            // Fallback for older browsers
            fallbackCopyTextToClipboard(text, this);
        }
    });
    
    // Fallback copy method using temporary input element
    function fallbackCopyTextToClipboard(text, buttonElement) {
        const $button = $(buttonElement);
        const originalText = $button.text();
        
        const textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Make the textarea out of viewport
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        let successful = false;
        try {
            successful = document.execCommand('copy');
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
        }
        
        document.body.removeChild(textArea);
        
        if (successful) {
            $button.text('Copied!');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        } else {
            $button.text('Copy failed');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        }
    }
}

    /**
     * Initialize manual sync functionality with better feedback
     */
    function initManualSync() {
        const $syncButton = $('#fds-manual-sync');
        const $syncStatus = $('#fds-sync-status');
        const $syncProgress = $('#fds-sync-progress');
        const $progressBar = $('.fds-progress-bar');
        const $progressStatus = $('.fds-progress-status');
        const $progressCounts = $('.fds-progress-counts');
        
        if ($syncButton.length) {
            $syncButton.on('click', function() {
                if (actionInProgress) return;
                
                if (confirm(fds_admin_vars.strings.confirm_sync)) {
                    actionInProgress = true;
                    $syncButton.prop('disabled', true);
                    $syncButton.text('Syncing...');
                    $syncStatus.text(fds_admin_vars.strings.sync_started);
                    
                    // Show and reset progress bar
                    $syncProgress.show();
                    $progressBar.css('width', '0%');
                    $progressStatus.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Preparing synchronization...');
                    $progressCounts.text('');
                    
                    // Start the sync
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_manual_sync',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Update status
                                $progressStatus.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Synchronization started');
                                
                                // Start polling for updates
                                checkSyncStatus();
                            } else {
                                actionInProgress = false;
                                $syncButton.prop('disabled', false);
                                $syncButton.text('Start Full Sync');
                                $syncStatus.text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                                $syncProgress.hide();
                            }
                        },
                        error: function(xhr, status, error) {
                            actionInProgress = false;
                            $syncButton.prop('disabled', false);
                            $syncButton.text('Start Full Sync');
                            $syncStatus.text('Error: ' + error);
                            $syncProgress.hide();
                        }
                    });
                }
            });
            
            // Function to check sync status with exponential backoff
            let checkInterval = 2000; // Start with 2 seconds
            const maxInterval = 10000; // Max 10 seconds
            
            function checkSyncStatus() {
                $.ajax({
                    url: fds_admin_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fds_check_sync_status',
                        nonce: fds_admin_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            const total = parseInt(data.total) || 0;
                            const pending = parseInt(data.pending) || 0;
                            const processing = parseInt(data.processing) || 0;
                            const completed = parseInt(data.completed) || 0;
                            const failed = parseInt(data.failed) || 0;
                            
                            // Reset interval if there's activity
                            if (pending > 0 || processing > 0) {
                                checkInterval = 2000;
                            } else {
                                // Gradually increase interval if no changes
                                checkInterval = Math.min(checkInterval * 1.5, maxInterval);
                            }
                            
                            // Calculate progress percentage
                            let progress = 0;
                            if (total > 0) {
                                progress = Math.round((completed / total) * 100);
                            }
                            
                            // Update progress bar
                            $progressBar.css('width', progress + '%');
                            
                            // Update status text
                            if (pending > 0 || processing > 0) {
                                $progressStatus.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Synchronizing...');
                                $progressCounts.html('<strong>' + completed + '</strong> completed, <strong>' + (pending + processing) + '</strong> pending, <strong>' + failed + '</strong> failed');
                                
                                // Continue polling
                                setTimeout(checkSyncStatus, checkInterval);
                            } else {
                                actionInProgress = false;
                                $syncButton.prop('disabled', false);
                                $syncButton.text('Start Full Sync');
                                
                                if (failed > 0) {
                                    $progressStatus.html('<span class="dashicons dashicons-warning" style="margin-right: 5px; color: #dba617;"></span> Synchronization completed with errors');
                                } else {
                                    $progressStatus.html('<span class="dashicons dashicons-yes-alt" style="margin-right: 5px; color: #4ab866;"></span> Synchronization completed successfully');
                                }
                                
                                $progressCounts.html('<strong>' + completed + '</strong> completed, <strong>' + failed + '</strong> failed');
                                $syncStatus.text('Sync completed at ' + new Date().toLocaleTimeString());
                                
                                // Hide progress after a delay
                                setTimeout(function() {
                                    $syncProgress.fadeOut('slow');
                                }, 8000);
                                
                                // Refresh stats
                                refreshStats();
                            }
                        } else {
                            actionInProgress = false;
                            $syncButton.prop('disabled', false);
                            $syncButton.text('Start Full Sync');
                            $syncStatus.text('Error checking sync status');
                            $syncProgress.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        // On error, increase check interval but continue polling
                        checkInterval = Math.min(checkInterval * 2, maxInterval);
                        
                        $progressStatus.html('<span class="dashicons dashicons-warning" style="margin-right: 5px;"></span> Checking status...');
                        $progressCounts.text('Connection issue, retrying...');
                        
                        // Continue polling even on error, but with longer interval
                        setTimeout(checkSyncStatus, checkInterval);
                    }
                });
            }
        }
    }

    /**
     * Initialize logs handling with improved UX
     */
    function initLogsHandling() {
        const $logLevelFilter = $('#fds-log-level-filter');
        const $refreshLogsBtn = $('#fds-refresh-logs');
        const $clearLogsBtn = $('#fds-clear-logs');
        const $logsTable = $('.fds-logs-table');
        const $logsBody = $('#fds-logs-tbody');
        const $prevBtn = $('#fds-logs-prev');
        const $nextBtn = $('#fds-logs-next');
        const $pageInfo = $('#fds-logs-page-info');
        
        // Current page and logs state
        let currentPage = 1;
        let totalPages = 1;
        let logsPerPage = 20;
        let isLoadingLogs = false;
        
        // Load logs if we're on the logs tab
        if ($logsTable.length) {
            loadLogs();
            
            // Event handlers
            $logLevelFilter.on('change', function() {
                currentPage = 1;
                loadLogs();
            });
            
            $refreshLogsBtn.on('click', function() {
                if (isLoadingLogs) return;
                loadLogs();
            });
            
            $clearLogsBtn.on('click', function() {
                if (isLoadingLogs) return;
                
                if (confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
                    isLoadingLogs = true;
                    $clearLogsBtn.prop('disabled', true);
                    $clearLogsBtn.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Clearing...');
                    
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_clear_logs',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            isLoadingLogs = false;
                            $clearLogsBtn.prop('disabled', false);
                            $clearLogsBtn.html('<span class="dashicons dashicons-trash" style="margin: 4px 5px 0 -5px;"></span> Clear Logs');
                            
                            if (response.success) {
                                currentPage = 1;
                                loadLogs();
                            } else {
                                alert('Error clearing logs: ' + (response.data ? response.data.message : 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            isLoadingLogs = false;
                            $clearLogsBtn.prop('disabled', false);
                            $clearLogsBtn.html('<span class="dashicons dashicons-trash" style="margin: 4px 5px 0 -5px;"></span> Clear Logs');
                            alert('Error clearing logs: ' + error);
                        }
                    });
                }
            });
            
            $prevBtn.on('click', function() {
                if (isLoadingLogs) return;
                
                if (currentPage > 1) {
                    currentPage--;
                    loadLogs();
                }
            });
            
            $nextBtn.on('click', function() {
                if (isLoadingLogs) return;
                
                if (currentPage < totalPages) {
                    currentPage++;
                    loadLogs();
                }
            });
        }
        
        // Function to load logs with better feedback
        function loadLogs() {
            if (isLoadingLogs) return;
            
            isLoadingLogs = true;
            $logsBody.html('<tr><td colspan="4" class="fds-loading-logs"><span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Loading logs...</td></tr>');
            $refreshLogsBtn.prop('disabled', true);
            $prevBtn.prop('disabled', true);
            $nextBtn.prop('disabled', true);
            
            $.ajax({
                url: fds_admin_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fds_get_logs',
                    nonce: fds_admin_vars.nonce,
                    level: $logLevelFilter.val(),
                    page: currentPage,
                    per_page: logsPerPage
                },
                success: function(response) {
                    isLoadingLogs = false;
                    $refreshLogsBtn.prop('disabled', false);
                    
                    if (response.success) {
                        const logs = response.data.logs;
                        const total = parseInt(response.data.total) || 0;
                        
                        // Calculate total pages
                        totalPages = Math.ceil(total / logsPerPage);
                        if (totalPages === 0) totalPages = 1;
                        
                        // Update page info
                        $pageInfo.text('Page ' + currentPage + ' of ' + totalPages);
                        
                        // Update pagination buttons
                        $prevBtn.prop('disabled', currentPage <= 1);
                        $nextBtn.prop('disabled', currentPage >= totalPages);
                        
                        if (logs && logs.length > 0) {
                            renderLogs(logs);
                        } else {
                            $logsBody.html('<tr><td colspan="4" class="fds-loading-logs"><span class="dashicons dashicons-info"></span> No logs found matching your criteria.</td></tr>');
                        }
                    } else {
                        $logsBody.html('<tr><td colspan="4" class="fds-loading-logs"><span class="dashicons dashicons-warning"></span> Error loading logs: ' + 
                            (response.data ? response.data.message : 'Unknown error') + '</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    isLoadingLogs = false;
                    $refreshLogsBtn.prop('disabled', false);
                    $logsBody.html('<tr><td colspan="4" class="fds-loading-logs"><span class="dashicons dashicons-warning"></span> Error loading logs: ' + error + '</td></tr>');
                }
            });
        }
        
        // Function to render logs in the table
        function renderLogs(logs) {
            let html = '';
            
            logs.forEach(function(log) {
                let context = '';
                try {
                    if (log.context) {
                        const contextObj = typeof log.context === 'string' ? JSON.parse(log.context) : log.context;
                        context = '<a href="#" class="fds-log-details-button" data-context=\'' + 
                            escapeHtml(JSON.stringify(contextObj, null, 2)) + '\'>View Details</a>';
                    } else {
                        context = 'N/A';
                    }
                } catch (e) {
                    context = 'Invalid data';
                }
                
                html += '<tr>' +
                    '<td>' + log.created_at + '</td>' +
                    '<td><span class="log-level log-level-' + log.level + '">' + log.level + '</span></td>' +
                    '<td>' + escapeHtml(log.message) + '</td>' +
                    '<td>' + context + '</td>' +
                    '</tr>';
            });
            
            $logsBody.html(html);
            
            // Attach event listeners for log details popup
            $('.fds-log-details-button').on('click', function(e) {
                e.preventDefault();
                
                const context = $(this).data('context');
                showLogDetailsPopup(context);
            });
        }
        
        // Function to show log details popup
        function showLogDetailsPopup(context) {
            // Create popup
            const $backdrop = $('<div class="fds-log-details-backdrop"></div>');
            const $popup = $('<div class="fds-log-details-popup">' +
                '<div class="fds-log-details-close">&times;</div>' +
                '<h3>Log Details</h3>' +
                '<div class="fds-log-details-content"></div>' +
                '</div>');
            
            // Add content
            $popup.find('.fds-log-details-content').text(typeof context === 'string' ? context : JSON.stringify(context, null, 2));
            
            // Add to body
            $('body').append($backdrop).append($popup);
            
            // Close handlers
            $backdrop.on('click', closePopup);
            $popup.find('.fds-log-details-close').on('click', closePopup);
            
            function closePopup() {
                $backdrop.remove();
                $popup.remove();
            }
        }
    }

    /**
     * Initialize sync stats dashboard with auto-refresh
     */
    function initSyncDashboard() {
        const $refreshStats = $('#fds-refresh-stats');
        const $forceProcess = $('#fds-force-process');
        const $retryFailed = $('#fds-retry-failed');
        const $actionStatus = $('#fds-action-status');
        const $totalFiles = $('#fds-total-files');
        const $syncedFiles = $('#fds-synced-files');
        const $pendingTasks = $('#fds-pending-tasks');
        const $failedTasks = $('#fds-failed-tasks');
        
        // Load stats on page load
        if ($refreshStats.length) {
            loadSyncStats();
            
            // Set up auto-refresh every 30 seconds
            const autoRefreshInterval = setInterval(function() {
                if (!actionInProgress) {
                    refreshStats(false); // Silent refresh
                }
            }, 30000);
            
            // Handle button clicks
            $refreshStats.on('click', function() {
                refreshStats(true); // Visible feedback
            });
            
            $forceProcess.on('click', function() {
                if (actionInProgress) return;
                
                if (confirm('Are you sure you want to force process pending tasks? This will attempt to process all tasks in the queue immediately.')) {
                    actionInProgress = true;
                    $forceProcess.prop('disabled', true);
                    $forceProcess.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Processing...');
                    
                    $actionStatus.removeClass('notice-success notice-error')
                        .addClass('notice notice-info')
                        .html('<p><span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Processing queue...</p>')
                        .show();
                    
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_force_process_queue',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            actionInProgress = false;
                            $forceProcess.prop('disabled', false);
                            $forceProcess.html('<span class="dashicons dashicons-controls-play" style="margin: 4px 5px 0 -5px;"></span> Process Queue Now');
                            
                            if (response.success) {
                                $actionStatus.removeClass('notice-info notice-error')
                                    .addClass('notice-success')
                                    .html('<p><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</p>');
                                    
                                // Refresh stats after processing
                                refreshStats(false);
                            } else {
                                $actionStatus.removeClass('notice-info notice-success')
                                    .addClass('notice-error')
                                    .html('<p><span class="dashicons dashicons-warning"></span> Error: ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                            }
                        },
                        error: function(xhr, status, error) {
                            actionInProgress = false;
                            $forceProcess.prop('disabled', false);
                            $forceProcess.html('<span class="dashicons dashicons-controls-play" style="margin: 4px 5px 0 -5px;"></span> Process Queue Now');
                            
                            $actionStatus.removeClass('notice-info notice-success')
                                .addClass('notice-error')
                                .html('<p><span class="dashicons dashicons-warning"></span> Error: ' + error + '</p>');
                        }
                    });
                }
            });
            
            $retryFailed.on('click', function() {
                if (actionInProgress) return;
                
                const failedCount = parseInt($failedTasks.text());
                if (failedCount === 0) {
                    alert('There are no failed tasks to retry.');
                    return;
                }
                
                if (confirm('Are you sure you want to retry all failed tasks?')) {
                    actionInProgress = true;
                    $retryFailed.prop('disabled', true);
                    $retryFailed.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Retrying...');
                    
                    $actionStatus.removeClass('notice-success notice-error')
                        .addClass('notice notice-info')
                        .html('<p><span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Retrying failed tasks...</p>')
                        .show();
                    
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_retry_failed_tasks',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            actionInProgress = false;
                            $retryFailed.prop('disabled', false);
                            $retryFailed.html('<span class="dashicons dashicons-image-rotate" style="margin: 4px 5px 0 -5px;"></span> Retry Failed Tasks');
                            
                            if (response.success) {
                                $actionStatus.removeClass('notice-info notice-error')
                                    .addClass('notice-success')
                                    .html('<p><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</p>');
                                    
                                // Refresh stats after retrying
                                refreshStats(false);
                            } else {
                                $actionStatus.removeClass('notice-info notice-success')
                                    .addClass('notice-error')
                                    .html('<p><span class="dashicons dashicons-warning"></span> Error: ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                            }
                        },
                        error: function(xhr, status, error) {
                            actionInProgress = false;
                            $retryFailed.prop('disabled', false);
                            $retryFailed.html('<span class="dashicons dashicons-image-rotate" style="margin: 4px 5px 0 -5px;"></span> Retry Failed Tasks');
                            
                            $actionStatus.removeClass('notice-info notice-success')
                                .addClass('notice-error')
                                .html('<p><span class="dashicons dashicons-warning"></span> Error: ' + error + '</p>');
                        }
                    });
                }
            });
        }
        
        // Master function to refresh stats
        function refreshStats(showFeedback = true) {
            if (showFeedback) {
                $refreshStats.prop('disabled', true);
                $refreshStats.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; margin-right: 5px;"></span> Refreshing...');
            }
            
            loadSyncStats(function() {
                if (showFeedback) {
                    $refreshStats.prop('disabled', false);
                    $refreshStats.html('<span class="dashicons dashicons-update" style="margin: 4px 5px 0 -5px;"></span> Refresh Stats');
                }
            });
        }
        
        // Function to load sync stats
        function loadSyncStats(callback) {
            $.ajax({
                url: fds_admin_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fds_get_sync_stats',
                    nonce: fds_admin_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Animated counter update
                        animateCounter($totalFiles, data.total_files);
                        animateCounter($syncedFiles, data.synced_files);
                        animateCounter($pendingTasks, data.pending_tasks);
                        animateCounter($failedTasks, data.failed_tasks);
                        
                        // Highlight changed values
                        highlightChanges($totalFiles, data.total_files);
                        highlightChanges($syncedFiles, data.synced_files);
                        highlightChanges($pendingTasks, data.pending_tasks);
                        highlightChanges($failedTasks, data.failed_tasks);
                    }
                    
                    if (typeof callback === 'function') {
                        callback();
                    }
                },
                error: function() {
                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            });
        }
        
        // Function to animate counter updates
        function animateCounter($element, newValue) {
            const currentValue = parseInt($element.text()) || 0;
            if (isNaN(newValue)) newValue = 0;
            
            // Only animate if there's a significant change
            if (Math.abs(currentValue - newValue) > 5) {
                $({ counter: currentValue }).animate({ counter: newValue }, {
                    duration: 500,
                    easing: 'swing',
                    step: function() {
                        $element.text(Math.round(this.counter));
                    },
                    complete: function() {
                        $element.text(newValue);
                    }
                });
            } else {
                $element.text(newValue);
            }
        }
        
        // Function to highlight changed values
        function highlightChanges($element, newValue) {
            const currentValue = parseInt($element.attr('data-value')) || 0;
            if (currentValue !== newValue) {
                $element.attr('data-value', newValue);
                
                // Flash highlight
                $element.css('transition', 'none');
                $element.css('background-color', newValue > currentValue ? '#d4edda' : (newValue < currentValue ? '#f8d7da' : 'transparent'));
                setTimeout(function() {
                    $element.css('transition', 'background-color 1s ease');
                    $element.css('background-color', 'transparent');
                }, 50);
            }
        }
    }

    /**
     * Initialize webhook button functionality
     */
    function initWebhookButtons() {
        console.log('Initializing webhook buttons'); // Debug message
        
        // Register webhook button
        $('#fds-register-webhook').on('click', function() {
            console.log('Register webhook button clicked'); // Debug message
            const $button = $(this);
            const $message = $('#fds-webhook-status-message');
            
            // Prevent multiple clicks
            if ($button.prop('disabled')) {
                return;
            }
            
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> Registering...');
            
            $message.removeClass('fds-status-success fds-status-error')
                .addClass('fds-status-info')
                .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> Registering webhook with Dropbox...')
                .show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fds_register_webhook',
                    nonce: fds_admin_vars.nonce
                },
                success: function(response) {
                    console.log('Register webhook response:', response); // Debug message
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-update"></span> Register Webhook');
                    
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
                    console.error('Register webhook error:', error, xhr.responseText); // Debug message
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-update"></span> Register Webhook');
                    
                    $message.removeClass('fds-status-info fds-status-success')
                        .addClass('fds-status-error')
                        .html('<span class="dashicons dashicons-warning"></span> Failed to communicate with the server. Please try again.');
                }
            });
        });
        
        // Test webhook button
        $('#fds-test-webhook').on('click', function() {
            console.log('Test webhook button clicked'); // Debug message
            const $button = $(this);
            const $message = $('#fds-webhook-status-message');
            
            // Prevent multiple clicks
            if ($button.prop('disabled')) {
                return;
            }
            
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> Testing...');
            
            $message.removeClass('fds-status-success fds-status-error')
                .addClass('fds-status-info')
                .html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear;"></span> Testing webhook connection...')
                .show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fds_test_webhook',
                    nonce: fds_admin_vars.nonce
                },
                success: function(response) {
                    console.log('Test webhook response:', response); // Debug message
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-hammer"></span> Test Webhook');
                    
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
                    console.error('Test webhook error:', error, xhr.responseText); // Debug message
                    $button.prop('disabled', false);
                    $button.html('<span class="dashicons dashicons-hammer"></span> Test Webhook');
                    
                    $message.removeClass('fds-status-info fds-status-success')
                        .addClass('fds-status-error')
                        .html('<span class="dashicons dashicons-warning"></span> Failed to communicate with the server. Please try again.');
                }
            });
        });
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Add rotation animation to stylesheet
    $('<style>@keyframes rotation{from{transform:rotate(0)}to{transform:rotate(360deg)}}</style>').appendTo('head');

})(jQuery);