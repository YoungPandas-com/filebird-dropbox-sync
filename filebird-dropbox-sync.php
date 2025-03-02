<?php
/**
 * Plugin Name: FileBird Dropbox Sync
 * Plugin URI: https://example.com/filebird-dropbox-sync
 * Description: Enables robust, two-way synchronization between FileBird folders and Dropbox.
 * Version: 1.0.0
 * Author: YP Studio
 * Author URI: https://yp.studio
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: filebird-dropbox-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FDS_VERSION', '1.0.0');
define('FDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FDS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FDS_DROPBOX_APP_KEY', ''); // Set your Dropbox API key here or in settings
define('FDS_DROPBOX_APP_SECRET', ''); // Set your Dropbox API secret here or in settings
define('FDS_ROOT_DROPBOX_FOLDER', '/Website'); // Default root folder in Dropbox

// Require the composer autoloader
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

/**
 * The code that runs during plugin activation.
 */
function activate_filebird_dropbox_sync() {
    require_once FDS_PLUGIN_DIR . 'includes/class-fds-activator.php';
    FDS_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_filebird_dropbox_sync() {
    require_once FDS_PLUGIN_DIR . 'includes/class-fds-deactivator.php';
    FDS_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_filebird_dropbox_sync');
register_deactivation_hook(__FILE__, 'deactivate_filebird_dropbox_sync');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once FDS_PLUGIN_DIR . 'includes/class-fds-core.php';

/**
 * Begins execution of the plugin.
 */
function run_filebird_dropbox_sync() {
    // Check if FileBird is active
    if (!class_exists('FileBird\\Model\\Folder')) {
        add_action('admin_notices', 'fds_filebird_missing_notice');
        return;
    }

    $plugin = new FDS_Core();
    $plugin->run();
}

/**
 * Admin notice for when FileBird is not active
 */
function fds_filebird_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('FileBird Dropbox Sync requires the FileBird plugin to be installed and activated.', 'filebird-dropbox-sync'); ?></p>
    </div>
    <?php
}

/**
 * Check if Action Scheduler is available.
 */
function fds_check_dependencies() {
    if (!class_exists('ActionScheduler') && is_admin()) {
        add_action('admin_notices', 'fds_action_scheduler_notice');
    }
}
add_action('plugins_loaded', 'fds_check_dependencies');

/**
 * Admin notice for when Action Scheduler is not active
 */
function fds_action_scheduler_notice() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('FileBird Dropbox Sync works best with Action Scheduler for optimal performance. Please install <a href="https://wordpress.org/plugins/action-scheduler/">Action Scheduler</a> for better handling of large media libraries.', 'filebird-dropbox-sync'); ?></p>
    </div>
    <?php
}

// Initialize the plugin
add_action('plugins_loaded', 'run_filebird_dropbox_sync');