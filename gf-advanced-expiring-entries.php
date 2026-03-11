<?php

/**
 * Plugin Name: GF Advanced Expiring Entries
 * Plugin URI:  https://github.com/guilamu/gf-advanced-expiring-entries
 * Description: Configure per-form expiration rules (feeds) for Gravity Forms entries. Supports fixed and dynamic expiry, multiple actions, pre-expiry notifications, and admin overrides.
 * Version: 1.2.1
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: gf-advanced-expiring-entries
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Update URI: https://github.com/guilamu/gf-advanced-expiring-entries/
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 */

defined('ABSPATH') || exit;

// Debug toggle: define GF_AEE_DEBUG as true in wp-config.php to enable verbose logging.
if (! defined('GF_AEE_DEBUG')) {
    define('GF_AEE_DEBUG', false);
}

define('GF_AEE_VERSION', '1.2.1');
define('GF_AEE_PLUGIN_FILE', __FILE__);
define('GF_AEE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GF_AEE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load translations and GitHub auto-updater at init (avoids _load_textdomain_just_in_time warning in WP 6.7+).
add_action('init', function () {
    load_plugin_textdomain('gf-advanced-expiring-entries', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // GitHub auto-updater (runs outside GF bootstrap so updates work even if GF is deactivated).
    require_once GF_AEE_PLUGIN_DIR . 'includes/class-github-updater.php';
});

// Bootstrap: load after Gravity Forms is ready.
add_action('gform_loaded', array('GF_AEE_Bootstrap', 'load'), 5);

class GF_AEE_Bootstrap
{

    public static function load()
    {

        if (! method_exists('GFForms', 'include_feed_addon_framework')) {
            return;
        }

        GFForms::include_feed_addon_framework();

        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-meta.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-feed-settings.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-processor.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-scheduler.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-log.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-expiry-runner.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-dashboard.php';
        require_once GF_AEE_PLUGIN_DIR . 'includes/class-gf-aee-addon.php';

        GFAddOn::register('GF_AEE_Addon');
    }
}

/**
 * Returns the singleton instance of the add-on.
 *
 * @return GF_AEE_Addon|false
 */
function gf_aee()
{
    return class_exists('GF_AEE_Addon') ? GF_AEE_Addon::get_instance() : false;
}

// ── Activation / Deactivation ──────────────────────────────────────────────
register_activation_hook(__FILE__, 'gf_aee_activate');
register_deactivation_hook(__FILE__, 'gf_aee_deactivate');

function gf_aee_activate()
{
    // Create tables.
    GF_AEE_Bootstrap::load(); // ensure classes are available.
    if (class_exists('GF_AEE_Expiry_Runner')) {
        GF_AEE_Expiry_Runner::create_deleted_entries_table();
    }
    if (class_exists('GF_AEE_Log')) {
        GF_AEE_Log::create_table();
    }

    // Schedule recurring expiry check.
    if (class_exists('GF_AEE_Scheduler')) {
        GF_AEE_Scheduler::reschedule();
    }
}

function gf_aee_deactivate()
{
    if (class_exists('GF_AEE_Scheduler')) {
        GF_AEE_Scheduler::unschedule_all();
    }
}

// ── Guilamu Bug Reporter integration ───────────────────────────────────────
add_action('plugins_loaded', function () {
    if (class_exists('Guilamu_Bug_Reporter')) {
        Guilamu_Bug_Reporter::register(array(
            'slug'        => 'gf-advanced-expiring-entries',
            'name'        => 'GF Advanced Expiring Entries',
            'version'     => GF_AEE_VERSION,
            'github_repo' => 'guilamu/gf-advanced-expiring-entries',
        ));
    }
}, 20);

add_filter('plugin_row_meta', 'gf_aee_plugin_row_meta', 10, 2);

/**
 * Add "Report a Bug" link to the plugin row meta.
 */
function gf_aee_plugin_row_meta($links, $file)
{
    if (plugin_basename(GF_AEE_PLUGIN_FILE) !== $file) {
        return $links;
    }

    if (class_exists('Guilamu_Bug_Reporter')) {
        $links[] = sprintf(
            '<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="%s" data-plugin-name="%s">%s</a>',
            'gf-advanced-expiring-entries',
            esc_attr__('GF Advanced Expiring Entries', 'gf-advanced-expiring-entries'),
            esc_html__('🐛 Report a Bug', 'gf-advanced-expiring-entries')
        );
    } else {
        $links[] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://github.com/guilamu/guilamu-bug-reporter/releases',
            esc_html__('🐛 Report a Bug (install Bug Reporter)', 'gf-advanced-expiring-entries')
        );
    }

    return $links;
}
