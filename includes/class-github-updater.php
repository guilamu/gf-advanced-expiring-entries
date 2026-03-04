<?php

/**
 * GitHub Auto-Updater
 *
 * Enables automatic updates from GitHub releases for WordPress plugins.
 *
 * @package GF_Advanced_Expiring_Entries
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class GF_AEE_GitHub_Updater
 *
 * Handles automatic updates from GitHub releases.
 */
class GF_AEE_GitHub_Updater
{

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    private const GITHUB_USER        = 'guilamu';
    private const GITHUB_REPO        = 'gf-advanced-expiring-entries';
    private const PLUGIN_FILE        = 'gf-advanced-expiring-entries/gf-advanced-expiring-entries.php';
    private const PLUGIN_SLUG        = 'gf-advanced-expiring-entries';
    private const PLUGIN_NAME        = 'GF Advanced Expiring Entries';
    private const PLUGIN_DESCRIPTION = 'Configure per-form expiration rules for Gravity Forms entries with multiple actions, scheduling, and admin overrides.';
    private const REQUIRES_WP        = '6.0';
    private const TESTED_WP          = '6.7';
    private const REQUIRES_PHP       = '8.1';
    private const TEXT_DOMAIN        = 'gf-advanced-expiring-entries';

    // =========================================================================
    // CACHE SETTINGS
    // =========================================================================

    private const CACHE_KEY        = 'gf_aee_github_release';
    private const CACHE_EXPIRATION = 43200; // 12 hours.

    /**
     * Optional GitHub token for private repos or to avoid rate limits.
     *
     * @var string
     */
    private const GITHUB_TOKEN = '';

    // =========================================================================
    // IMPLEMENTATION
    // =========================================================================

    /**
     * Initialize the updater.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('update_plugins_github.com', array(self::class, 'check_for_update'), 10, 4);
        add_filter('plugins_api', array(self::class, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array(self::class, 'fix_folder_name'), 10, 4);
    }

    /**
     * Get release data from GitHub with caching.
     *
     * @return array|null Release data or null on failure.
     */
    private static function get_release_data(): ?array
    {
        $release_data = get_transient(self::CACHE_KEY);

        if (false !== $release_data && is_array($release_data)) {
            return $release_data;
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, self::GITHUB_REPO),
            array(
                'user-agent' => 'WordPress/' . self::PLUGIN_SLUG,
                'timeout'    => 15,
                'headers'    => ! empty(self::GITHUB_TOKEN)
                    ? array('Authorization' => 'token ' . self::GITHUB_TOKEN)
                    : array(),
            )
        );

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . ' Update Error: ' . $response->get_error_message());
            }
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . " Update Error: HTTP {$response_code}");
            }
            return null;
        }

        $release_data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($release_data['tag_name'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . ' Update Error: No tag_name in release');
            }
            return null;
        }

        set_transient(self::CACHE_KEY, $release_data, self::CACHE_EXPIRATION);

        return $release_data;
    }

    /**
     * Get the download URL for the plugin package.
     *
     * Prefers custom release assets (e.g., gf-advanced-expiring-entries.zip)
     * over GitHub's auto-generated zipball.
     *
     * @param array $release_data Release data from GitHub API.
     * @return string Download URL.
     */
    private static function get_package_url(array $release_data): string
    {
        if (! empty($release_data['assets']) && is_array($release_data['assets'])) {
            foreach ($release_data['assets'] as $asset) {
                if (
                    isset($asset['browser_download_url']) &&
                    isset($asset['name']) &&
                    str_ends_with($asset['name'], '.zip')
                ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        return $release_data['zipball_url'] ?? '';
    }

    /**
     * Check for plugin updates from GitHub.
     *
     * @param array|false $update      The plugin update data.
     * @param array       $plugin_data Plugin headers.
     * @param string      $plugin_file Plugin file path.
     * @param array       $locales     Installed locales.
     * @return array|false Updated plugin data or false.
     */
    public static function check_for_update($update, array $plugin_data, string $plugin_file, $locales)
    {
        if (self::PLUGIN_FILE !== $plugin_file) {
            return $update;
        }

        $release_data = self::get_release_data();
        if (null === $release_data) {
            return $update;
        }

        $new_version = ltrim($release_data['tag_name'], 'v');

        if (version_compare($plugin_data['Version'], $new_version, '>=')) {
            return $update;
        }

        return array(
            'version'       => $new_version,
            'package'       => self::get_package_url($release_data),
            'url'           => $release_data['html_url'],
            'tested'        => self::TESTED_WP,
            'requires_php'  => self::REQUIRES_PHP,
            'compatibility' => new stdClass(),
            'icons'         => array(),
            'banners'       => array(),
        );
    }

    /**
     * Provide plugin information for the WordPress plugin details popup.
     *
     * @param false|object|array $res    The result object or array.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return false|object Plugin information or false.
     */
    public static function plugin_info($res, $action, $args)
    {
        if ('plugin_information' !== $action) {
            return $res;
        }

        if (! isset($args->slug) || self::PLUGIN_SLUG !== $args->slug) {
            return $res;
        }

        $release_data = self::get_release_data();

        // Always return a valid response to prevent WordPress.org fallback.
        if (null === $release_data) {
            $plugin_file   = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
            $plugin_data   = get_plugin_data($plugin_file, false, false);
            $current_version = $plugin_data['Version'] ?? '1.0.0';

            $res                = new stdClass();
            $res->name          = self::PLUGIN_NAME;
            $res->slug          = self::PLUGIN_SLUG;
            $res->plugin        = self::PLUGIN_FILE;
            $res->version       = $current_version;
            $res->author        = sprintf('<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER);
            $res->homepage      = sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO);
            $res->requires      = self::REQUIRES_WP;
            $res->tested        = self::TESTED_WP;
            $res->requires_php  = self::REQUIRES_PHP;
            $res->sections      = array(
                'description' => self::PLUGIN_DESCRIPTION,
                'changelog'   => sprintf(
                    '<p>Unable to fetch changelog from GitHub. Visit <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for the latest changelog.</p>',
                    self::GITHUB_USER,
                    self::GITHUB_REPO
                ),
            );
            return $res;
        }

        $new_version = ltrim($release_data['tag_name'], 'v');

        $res                = new stdClass();
        $res->name          = self::PLUGIN_NAME;
        $res->slug          = self::PLUGIN_SLUG;
        $res->plugin        = self::PLUGIN_FILE;
        $res->version       = $new_version;
        $res->author        = sprintf('<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER);
        $res->homepage      = sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO);
        $res->download_link = self::get_package_url($release_data);
        $res->requires      = self::REQUIRES_WP;
        $res->tested        = self::TESTED_WP;
        $res->requires_php  = self::REQUIRES_PHP;
        $res->last_updated  = $release_data['published_at'] ?? '';
        $res->sections      = array(
            'description' => self::PLUGIN_DESCRIPTION,
            'changelog'   => ! empty($release_data['body'])
                ? nl2br(esc_html($release_data['body']))
                : sprintf(
                    'See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.',
                    self::GITHUB_USER,
                    self::GITHUB_REPO
                ),
        );

        return $res;
    }

    /**
     * Rename the extracted folder to match the expected plugin folder name.
     *
     * @param string      $source        File source location.
     * @param string      $remote_source Remote file source location.
     * @param WP_Upgrader $upgrader      WP_Upgrader instance.
     * @param array       $hook_extra    Extra arguments passed to hooked filters.
     * @return string|WP_Error The corrected source path or WP_Error on failure.
     */
    public static function fix_folder_name($source, $remote_source, $upgrader, $hook_extra)
    {
        global $wp_filesystem;

        if (! isset($hook_extra['plugin'])) {
            return $source;
        }

        if (self::PLUGIN_FILE !== $hook_extra['plugin']) {
            return $source;
        }

        $correct_folder = dirname(self::PLUGIN_FILE);
        $source_folder  = basename(untrailingslashit($source));

        if ($source_folder === $correct_folder) {
            return $source;
        }

        $new_source = trailingslashit($remote_source) . $correct_folder . '/';

        if ($wp_filesystem && $wp_filesystem->move($source, $new_source)) {
            return $new_source;
        }

        if ($wp_filesystem && $wp_filesystem->copy($source, $new_source, true) && $wp_filesystem->delete($source, true)) {
            return $new_source;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '%s updater: failed to rename update folder from %s to %s',
                self::PLUGIN_NAME,
                $source,
                $new_source
            ));
        }

        return new WP_Error(
            'rename_failed',
            __('Unable to rename the update folder. Please retry or update manually.', 'gf-advanced-expiring-entries')
        );
    }
}

// Initialize the updater.
GF_AEE_GitHub_Updater::init();
