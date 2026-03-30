<?php

/**
 * GitHub Auto-Updater
 *
 * Enables automatic updates from GitHub releases for WordPress plugins.
 *
 * @package GF_Advanced_Expiring_Entries
 */

if (!defined('ABSPATH')) {
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
    private const REQUIRES_GF        = '2.7';
    private const TEXT_DOMAIN        = 'gf-advanced-expiring-entries';

    // =========================================================================
    // CACHE SETTINGS
    // =========================================================================

    private const CACHE_KEY        = 'gf_aee_github_release';
    private const CACHE_EXPIRATION = 43200; // 12 hours.

    /**
     * Optional GitHub token for private repos or to avoid rate limits.
     * Use a Classic PAT with `repo` scope or a fine-grained token with read access to the repo.
     *
     * @var string
     */
    private const GITHUB_TOKEN = '';

    // =========================================================================
    // IMPLEMENTATION (no changes needed below this line)
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
        add_action('admin_head', array(self::class, 'plugin_info_css'));
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
                'headers'    => !empty(self::GITHUB_TOKEN)
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
        if (!empty($release_data['assets']) && is_array($release_data['assets'])) {
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
            'id'            => 'github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'slug'          => self::PLUGIN_SLUG,
            'plugin'        => self::PLUGIN_FILE,
            'new_version'   => $new_version,
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
     * Reads sections (description, installation, FAQ, changelog) from the
     * local README.md instead of fetching from the GitHub release body.
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

        if (!isset($args->slug) || self::PLUGIN_SLUG !== $args->slug) {
            return $res;
        }

        $plugin_file  = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
        $plugin_data  = get_plugin_data($plugin_file, false, false);
        $release_data = self::get_release_data();

        $version = $release_data
            ? ltrim($release_data['tag_name'], 'v')
            : ($plugin_data['Version'] ?? '1.0.0');

        $res               = new stdClass();
        $res->name         = self::PLUGIN_NAME;
        $res->slug         = self::PLUGIN_SLUG;
        $res->plugin       = self::PLUGIN_FILE;
        $res->version      = $version;
        $res->author       = sprintf('<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER);
        $res->homepage     = sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO);
        $res->requires     = self::REQUIRES_WP;
        $res->tested       = get_bloginfo('version');
        $res->requires_php = self::REQUIRES_PHP;

        if ($release_data) {
            $res->download_link = self::get_package_url($release_data);
            $res->last_updated  = $release_data['published_at'] ?? '';
        }

        // Build sections from local README.md.
        $readme = self::parse_readme();

        $res->sections = array(
            'description' => !empty($readme['description'])
                ? $readme['description']
                : '<p>' . esc_html(self::PLUGIN_DESCRIPTION) . '</p>',
        );

        if (!empty($readme['installation'])) {
            $res->sections['installation'] = $readme['installation'];
        }

        if (!empty($readme['faq'])) {
            $res->sections['faq'] = $readme['faq'];
        }

        $res->sections['changelog'] = !empty($readme['changelog'])
            ? $readme['changelog']
            : sprintf(
                '<p>See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.</p>',
                esc_attr(self::GITHUB_USER),
                esc_attr(self::GITHUB_REPO)
            );

        return $res;
    }

    /**
     * Inject CSS overrides and optional sidebar info in the plugin-information iframe.
     */
    public static function plugin_info_css(): void
    {
        if (!isset($_GET['plugin'], $_GET['tab'])) {
            return;
        }
        if ('plugin-information' !== sanitize_text_field(wp_unslash($_GET['tab']))
            || self::PLUGIN_SLUG !== sanitize_text_field(wp_unslash($_GET['plugin']))) {
            return;
        }

        echo '<style>'
            . '#section-holder .section h2 { margin: 1.5em 0 0.5em; clear: none; }'
            . '#section-holder .section h3 { margin: 1.5em 0 0.5em; }'
            . '#section-holder .section > :first-child { margin-top: 0; }'
            . '.md-table { display: table; width: 100%; border-collapse: collapse; margin: 1em 0; font-size: 13px; }'
            . '.md-tr { display: table-row; }'
            . '.md-tr > span { display: table-cell; padding: 6px 10px; border: 1px solid #ddd; vertical-align: top; }'
            . '.md-th > span { font-weight: 600; background: #f5f5f5; }'
            . '</style>';

        // Gravity Forms add-on: add sidebar "Requires Gravity Forms" line.
        if (defined('self::REQUIRES_GF')) {
            $gf_version = esc_html(self::REQUIRES_GF);
            echo '<script>'
                . 'document.addEventListener("DOMContentLoaded",function(){'
                . 'var items=document.querySelectorAll(".fyi ul li");'
                . 'var php=null;'
                . 'for(var i=0;i<items.length;i++){if(items[i].textContent.indexOf("Requires PHP")!==-1){php=items[i];break;}}'
                . 'if(!php)return;'
                . 'var li=document.createElement("li");'
                . 'li.innerHTML="<strong>Requires Gravity Forms:<\/strong> ' . $gf_version . ' or higher";'
                . 'php.parentNode.insertBefore(li,php.nextSibling);'
                . '});'
                . '</script>';
        }
    }

    // ------------------------------------------------------------------
    // README.md parsing
    // ------------------------------------------------------------------

    /**
     * Parse the local README.md into description, installation, FAQ and changelog HTML.
     *
     * @return array{description: string, installation: string, faq: string, changelog: string}
     */
    private static function parse_readme(): array
    {
        $readme_path = WP_PLUGIN_DIR . '/' . dirname(self::PLUGIN_FILE) . '/README.md';

        if (!file_exists($readme_path)) {
            return array();
        }

        $content = file_get_contents($readme_path);
        if (false === $content) {
            return array();
        }

        // Remove the main title line (# Title).
        $content = preg_replace('/^#\s+[^\n]+\n*/m', '', $content, 1);

        $utility_sections = array(
            'changelog', 'requirements', 'installation', 'faq',
            'project structure', 'acknowledgements', 'license',
        );

        // Split content by ## headers.
        $parts = preg_split('/^##\s+/m', $content);

        $description  = trim($parts[0] ?? '');
        $installation = '';
        $faq          = '';
        $changelog    = '';

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $lines = explode("\n", $parts[$i], 2);
            $title = strtolower(trim($lines[0]));
            $body  = trim($lines[1] ?? '');

            if ('installation' === $title) {
                $installation .= $body . "\n\n";
            } elseif ('faq' === $title) {
                $faq .= $body . "\n\n";
            } elseif ('changelog' === $title) {
                $changelog .= $body . "\n\n";
            } elseif (!in_array($title, $utility_sections, true)) {
                $description .= "\n\n## " . trim($lines[0]) . "\n" . $body;
            }
        }

        return array(
            'description'  => self::markdown_to_html(trim($description)),
            'installation' => self::markdown_to_html(trim($installation)),
            'faq'          => self::markdown_to_html(trim($faq)),
            'changelog'    => self::markdown_to_html(trim($changelog)),
        );
    }

    /**
     * Convert Markdown to HTML using Parsedown.
     *
     * Images are stripped before conversion since they are not useful
     * inside the WordPress plugin-information modal.
     */
    private static function markdown_to_html(string $markdown): string
    {
        if ('' === $markdown) {
            return '';
        }

        // Remove images (not useful in the modal).
        $markdown = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $markdown);

        if (!class_exists('Parsedown')) {
            require_once __DIR__ . '/Parsedown.php';
        }

        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);

        $html = $parsedown->text($markdown);

        // Convert <table> to wp_kses-safe <div>/<span> structures.
        $html = self::tables_to_divs($html);

        return $html;
    }

    /**
     * Convert HTML tables to div/span structures compatible with wp_kses.
     *
     * @param string $html HTML containing <table> elements.
     * @return string HTML with tables replaced by styled div/span.
     */
    private static function tables_to_divs(string $html): string
    {
        return preg_replace_callback('/<table>(.*?)<\/table>/s', function ($m) {
            $table_html = $m[1];
            $output = '<div class="md-table">';

            preg_match_all('/<tr>(.*?)<\/tr>/s', $table_html, $rows);

            foreach ($rows[1] as $idx => $row_content) {
                $is_header = (0 === $idx && strpos($table_html, '<thead>') !== false);
                $row_class = $is_header ? 'md-tr md-th' : 'md-tr';

                preg_match_all('/<t[hd]>(.*?)<\/t[hd]>/s', $row_content, $cells);

                $output .= '<div class="' . $row_class . '">';
                foreach ($cells[1] as $cell) {
                    $output .= '<span>' . $cell . '</span>';
                }
                $output .= '</div>';
            }

            $output .= '</div>';
            return $output;
        }, $html);
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

        if (!isset($hook_extra['plugin'])) {
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
            __('Unable to rename the update folder. Please retry or update manually.', self::TEXT_DOMAIN)
        );
    }
}

// Initialize the updater.
GF_AEE_GitHub_Updater::init();
