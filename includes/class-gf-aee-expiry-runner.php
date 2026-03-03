<?php

/**
 * GF_AEE_Expiry_Runner — Executes the configured expiry action for a single entry.
 */

defined('ABSPATH') || exit;

class GF_AEE_Expiry_Runner
{

    /**
     * Pass a message to GF's built-in log (Forms › System Status › Logs).
     */
    private static function addon_log($message)
    {
        $addon = gf_aee();
        if ($addon) {
            $addon->log_debug(__CLASS__ . ': ' . $message);
        }
    }

    /**
     * Execute expiry for a single entry.
     *
     * @param int $entry_id Entry ID.
     */
    public static function run($entry_id)
    {

        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry)) {
            return;
        }

        // Skip entries already trashed or not active in GF (e.g. handled by GF's own auto-deletion).
        $entry_status = rgar($entry, 'status');
        if ($entry_status !== 'active') {
            $skip_msg = sprintf(
                /* translators: %s = GF entry status */
                __('Skipped: entry status is "%s" (already handled outside this plugin).', 'gf-advanced-expiring-entries'),
                $entry_status
            );
            GF_AEE_Meta::mark_expired($entry_id);
            GF_AEE_Meta::log_action($entry_id, 'skip', false, $skip_msg);
            GF_AEE_Log::write($entry_id, (int) rgar($entry, 'form_id'), 0, 'skip', false, $skip_msg);
            self::addon_log(sprintf('Entry #%d skipped — %s', $entry_id, $skip_msg));
            return;
        }

        $feed_id = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::FEED_ID);
        $addon   = gf_aee();
        $feed    = $addon ? $addon->get_feed($feed_id) : null;

        if (! $feed) {
            $no_feed_msg = __('Feed not found.', 'gf-advanced-expiring-entries');
            GF_AEE_Meta::log_action($entry_id, 'unknown', false, $no_feed_msg);
            GF_AEE_Log::write($entry_id, (int) rgar($entry, 'form_id'), (int) $feed_id, 'unknown', false, $no_feed_msg);
            self::addon_log(sprintf('Entry #%d — feed #%d not found.', $entry_id, $feed_id));
            return;
        }

        // Check override: if override_ts exists and is in the future → skip.
        $override_ts = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::OVERRIDE_TS);
        if (! empty($override_ts) && (int) $override_ts > time()) {
            return; // not yet due.
        }

        $meta   = rgar($feed, 'meta');
        $action = rgar($meta, 'expiry_action', 'trash');
        $form   = GFAPI::get_form(rgar($feed, 'form_id'));

        // ── Dry-run mode ───────────────────────────────────────────────
        $dry_run = false;
        if ($addon) {
            $settings = $addon->get_plugin_settings();
            $dry_run  = (bool) rgar($settings, 'enable_dry_run', false);
        }

        /**
         * Fires just before the expiry action is executed.
         *
         * @param int    $entry_id Entry ID.
         * @param string $action   The configured action slug.
         * @param array  $feed     Feed configuration.
         */
        do_action('gf_aee_before_expiry_action', $entry_id, $action, $feed);

        $success = false;

        if ($dry_run) {
            $dry_run_msg = __('[DRY-RUN] Would execute action.', 'gf-advanced-expiring-entries');
            GF_AEE_Meta::log_action($entry_id, $action, true, $dry_run_msg);
            GF_AEE_Log::write($entry_id, (int) rgar($feed, 'form_id'), (int) $feed_id, $action, true, $dry_run_msg);
            self::addon_log(sprintf('[DRY-RUN] Entry #%d — action: %s', $entry_id, $action));
            GF_AEE_Meta::mark_expired($entry_id);
            do_action('gf_aee_after_expiry_action', $entry_id, $action, $feed, true);
            return;
        }

        // ── Dispatch ───────────────────────────────────────────────────
        switch ($action) {

            case 'trash':
                $result  = GFAPI::update_entry_property($entry_id, 'status', 'trash');
                if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] Trash entry #' . $entry_id . ': result=' . var_export($result, true) . ' type=' . gettype($result)); }
                $success = ($result === true || (is_int($result) && $result > 0));
                if (! $success && ! is_wp_error($result)) {
                    // Double-check: did the status actually change in the DB?
                    $verify = GFAPI::get_entry($entry_id);
                    if (! is_wp_error($verify) && rgar($verify, 'status') === 'trash') {
                        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] Trash entry #' . $entry_id . ': API returned falsy but entry IS trashed. Treating as success.'); }
                        $success = true;
                    }
                }
                break;

            case 'delete':
                // Backup before permanent deletion.
                self::backup_entry($entry, $form);
                $result  = GFAPI::delete_entry($entry_id);
                if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] Delete entry #' . $entry_id . ': result=' . var_export($result, true) . ' type=' . gettype($result)); }
                $success = ($result === true || (is_int($result) && $result > 0));
                break;

            case 'change_status':
                $target  = rgar($meta, 'target_status', 'read');
                if ($target === 'starred') {
                    $result = GFAPI::update_entry_property($entry_id, 'is_starred', 1);
                } elseif ($target === 'read') {
                    $result = GFAPI::update_entry_property($entry_id, 'is_read', 1);
                } elseif ($target === 'unread') {
                    $result = GFAPI::update_entry_property($entry_id, 'is_read', 0);
                }
                $success = (isset($result) && $result && ! is_wp_error($result));
                break;

            case 'update_field':
                $field_id    = rgar($meta, 'target_field_id');
                $field_value = rgar($meta, 'target_field_value', '');
                if ($field_id) {
                    $entry[$field_id] = $field_value;
                    $result  = GFAPI::update_entry($entry);
                    $success = ($result && ! is_wp_error($result));
                }
                break;

            case 'webhook':
                $url    = rgar($meta, 'webhook_url');
                $method = rgar($meta, 'webhook_method', 'POST');
                if ($url) {
                    $payload = array(
                        'entry_id' => $entry_id,
                        'form_id'  => rgar($entry, 'form_id'),
                        'entry'    => $entry,
                    );
                    if ($method === 'GET') {
                        $response = wp_remote_get(add_query_arg(array(
                            'entry_id' => $entry_id,
                            'form_id'  => rgar($entry, 'form_id'),
                        ), $url));
                    } else {
                        $response = wp_remote_post($url, array(
                            'body'    => wp_json_encode($payload),
                            'headers' => array('Content-Type' => 'application/json'),
                        ));
                    }
                    $success = (! is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400);
                }
                break;

            case 'notification':
                $notification_id = rgar($meta, 'notification_id');
                if ($notification_id && ! is_wp_error($form)) {
                    $notification = rgar(rgar($form, 'notifications', array()), $notification_id);
                    if ($notification) {
                        GFCommon::send_notification($notification, $form, $entry);
                        $success = true;
                    }
                }
                break;

            default:
                /**
                 * Allow third-party actions to be handled via filter/hook.
                 */
                $success = apply_filters('gf_aee_custom_expiry_action', false, $action, $entry_id, $feed, $form);
                break;
        }

        // Update meta.
        $result_msg = $success
            ? __('Action completed.', 'gf-advanced-expiring-entries')
            : sprintf(
                /* translators: %s = var_export of the API return value */
                __('Action failed. API returned: %s', 'gf-advanced-expiring-entries'),
                isset($result) ? var_export($result, true) : 'N/A'
            );

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] Entry #' . $entry_id . ' action=' . $action . ' success=' . ($success ? 'YES' : 'NO') . ' result=' . (isset($result) ? var_export($result, true) : 'N/A')); }

        GF_AEE_Meta::mark_expired($entry_id);
        GF_AEE_Meta::log_action($entry_id, $action, $success, $result_msg);
        GF_AEE_Log::write($entry_id, (int) rgar($feed, 'form_id'), (int) $feed_id, $action, $success, $result_msg);
        self::addon_log(sprintf(
            'Entry #%d — action: %s — %s',
            $entry_id,
            $action,
            $success ? 'OK' : 'FAILED'
        ));

        /**
         * Fires after an expiry action completes.
         */
        do_action('gf_aee_after_expiry_action', $entry_id, $action, $feed, $success);
    }

	/* ─── Deleted entries backup table ────────────────────────────────── */

    /**
     * Create the deleted_entries backup table.
     */
    public static function create_deleted_entries_table()
    {
        global $wpdb;

        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			form_id MEDIUMINT UNSIGNED NOT NULL,
			entry_data LONGTEXT NOT NULL,
			action_log LONGTEXT,
			deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY entry_id (entry_id),
			KEY form_id (form_id)
		) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Backup an entry before permanent deletion.
     *
     * @param array      $entry Entry array.
     * @param array|null $form  Form array (optional, for context).
     */
    public static function backup_entry($entry, $form = null)
    {
        global $wpdb;

        $action_log = GF_AEE_Meta::get_action_log((int) rgar($entry, 'id'));

        $wpdb->insert(
            self::get_table_name(),
            array(
                'entry_id'   => rgar($entry, 'id'),
                'form_id'    => rgar($entry, 'form_id'),
                'entry_data' => wp_json_encode($entry),
                'action_log' => $action_log ? wp_json_encode($action_log) : null,
                'deleted_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
    }

    /**
     * Get the full table name.
     */
    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'gf_aee_deleted_entries';
    }
}
