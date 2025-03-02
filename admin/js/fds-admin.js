/**
 * Admin JavaScript for FileBird Dropbox Sync
 */
(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        // Dropbox connection
        initDropboxConnection();
        
        // Manual sync
        initManualSync();
        
        // Logs
        initLogsHandling();
    });

    /**
     * Initialize Dropbox connection functionality
     */
    function initDropboxConnection() {
        const $connectButton = $('#fds-connect-dropbox');
        const $disconnectButton = $('#fds-disconnect-dropbox');
        const $statusContainer = $('#fds-connection-status');
        
        if ($connectButton.length) {
            $connectButton.on('click', function() {
                $statusContainer.removeClass('hidden').addClass('info').text(fds_admin_vars.strings.connecting);
                
                $.ajax({
                    url: fds_admin_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fds_oauth_start',
                        nonce: fds_admin_vars.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.auth_url) {
                            // Open Dropbox authorization page in a new window/tab
                            window.open(response.data.auth_url, '_blank');
                        } else {
                            $statusContainer.removeClass('info').addClass('error')
                                .text(fds_admin_vars.strings.error + ' ' + (response.data ? response.data.message : 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        $statusContainer.removeClass('info').addClass('error')
                            .text(fds_admin_vars.strings.error + ' ' + error);
                    }
                });
            });
        }
        
        if ($disconnectButton.length) {
            $disconnectButton.on('click', function() {
                if (confirm('Are you sure you want to disconnect from Dropbox? This will stop synchronization until you reconnect.')) {
                    $statusContainer.removeClass('hidden').addClass('info').text('Disconnecting from Dropbox...');
                    
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_oauth_disconnect',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $statusContainer.removeClass('info').addClass('success')
                                    .text('Successfully disconnected from Dropbox.');
                                
                                // Reload page after a short delay
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                $statusContainer.removeClass('info').addClass('error')
                                    .text(fds_admin_vars.strings.error + ' ' + (response.data ? response.data.message : 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            $statusContainer.removeClass('info').addClass('error')
                                .text(fds_admin_vars.strings.error + ' ' + error);
                        }
                    });
                }
            });
        }
    }

    /**
     * Initialize manual sync functionality
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
                if (confirm(fds_admin_vars.strings.confirm_sync)) {
                    $syncButton.prop('disabled', true);
                    $syncStatus.text(fds_admin_vars.strings.sync_started);
                    $syncProgress.show();
                    $progressBar.css('width', '0%');
                    $progressStatus.text('Processing...');
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
                                // Start polling for updates
                                checkSyncStatus();
                            } else {
                                $syncButton.prop('disabled', false);
                                $syncStatus.text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                                $syncProgress.hide();
                            }
                        },
                        error: function(xhr, status, error) {
                            $syncButton.prop('disabled', false);
                            $syncStatus.text('Error: ' + error);
                            $syncProgress.hide();
                        }
                    });
                }
            });
            
            // Function to check sync status
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
                            
                            // Calculate progress percentage
                            let progress = 0;
                            if (total > 0) {
                                progress = Math.round((completed / total) * 100);
                            }
                            
                            // Update progress bar
                            $progressBar.css('width', progress + '%');
                            
                            // Update status text
                            if (pending > 0 || processing > 0) {
                                $progressStatus.text('Synchronizing...');
                                $progressCounts.text(`Completed: ${completed}, Pending: ${pending + processing}, Failed: ${failed}`);
                                
                                // Continue polling
                                setTimeout(checkSyncStatus, 2000);
                            } else {
                                $progressStatus.text('Synchronization completed!');
                                $progressCounts.text(`Completed: ${completed}, Failed: ${failed}`);
                                $syncButton.prop('disabled', false);
                                $syncStatus.text('Sync completed at ' + new Date().toLocaleTimeString());
                                
                                // Hide progress after a delay
                                setTimeout(function() {
                                    $syncProgress.hide();
                                }, 5000);
                            }
                        } else {
                            $syncButton.prop('disabled', false);
                            $syncStatus.text('Error checking sync status');
                            $syncProgress.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        $syncButton.prop('disabled', false);
                        $syncStatus.text('Error checking sync status: ' + error);
                        $syncProgress.hide();
                    }
                });
            }
        }
    }

    /**
     * Initialize logs handling
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
        
        // Load logs if we're on the logs tab
        if ($logsTable.length) {
            loadLogs();
            
            // Event handlers
            $logLevelFilter.on('change', function() {
                currentPage = 1;
                loadLogs();
            });
            
            $refreshLogsBtn.on('click', function() {
                loadLogs();
            });
            
            $clearLogsBtn.on('click', function() {
                if (confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
                    $.ajax({
                        url: fds_admin_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fds_clear_logs',
                            nonce: fds_admin_vars.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                currentPage = 1;
                                loadLogs();
                            } else {
                                alert('Error clearing logs: ' + (response.data ? response.data.message : 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            alert('Error clearing logs: ' + error);
                        }
                    });
                }
            });
            
            $prevBtn.on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    loadLogs();
                }
            });
            
            $nextBtn.on('click', function() {
                if (currentPage < totalPages) {
                    currentPage++;
                    loadLogs();
                }
            });
        }
        
        // Function to load logs
        function loadLogs() {
            $logsBody.html('<tr><td colspan="4" class="fds-loading-logs">Loading logs...</td></tr>');
            
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
                    if (response.success) {
                        const logs = response.data.logs;
                        const total = parseInt(response.data.total) || 0;
                        
                        // Calculate total pages
                        totalPages = Math.ceil(total / logsPerPage);
                        
                        // Update page info
                        $pageInfo.text('Page ' + currentPage + ' of ' + (totalPages || 1));
                        
                        // Update pagination buttons
                        $prevBtn.prop('disabled', currentPage <= 1);
                        $nextBtn.prop('disabled', currentPage >= totalPages);
                        
                        if (logs && logs.length > 0) {
                            renderLogs(logs);
                        } else {
                            $logsBody.html('<tr><td colspan="4" class="fds-loading-logs">No logs found.</td></tr>');
                        }
                    } else {
                        $logsBody.html('<tr><td colspan="4" class="fds-loading-logs">Error loading logs: ' + 
                            (response.data ? response.data.message : 'Unknown error') + '</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    $logsBody.html('<tr><td colspan="4" class="fds-loading-logs">Error loading logs: ' + error + '</td></tr>');
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
                        const contextData = JSON.parse(log.context);
                        context = '<a href="#" class="fds-log-details-button" data-context=\'' + 
                            escapeHtml(JSON.stringify(contextData, null, 2)) + '\'>View Details</a>';
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
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

})(jQuery);