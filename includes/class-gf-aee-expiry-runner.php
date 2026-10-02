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

            case 'anonymize':
                $form_obj = is_wp_error($form)
                    ? GFAPI::get_form(rgar($entry, 'form_id'))
                    : $form;

                if (is_wp_error($form_obj) || empty($form_obj['fields'])) {
                    $success = false;
                    break;
                }

                // Step 1 — optionally delete physical files BEFORE clearing field values.
                if (! empty($meta['anonymize_delete_files'])) {
                    $files = self::delete_entry_files($entry, $form_obj);
                    foreach ($files['meta_keys'] as $meta_key) {
                        gform_delete_meta($entry_id, $meta_key);
                    }
                }

                // Step 2 — blank every numeric (field-ID) key in the entry array.
                $anonymized_entry = $entry;
                foreach ($anonymized_entry as $key => $value) {
                    if (is_numeric($key)) {
                        $anonymized_entry[$key] = '';
                    }
                }

                // Step 3 — optionally clear personal metadata columns.
                if (! empty($meta['anonymize_clear_ip'])) {
                    $anonymized_entry['ip']         = '';
                    $anonymized_entry['source_url'] = '';
                }
                if (! empty($meta['anonymize_clear_created_by'])) {
                    $anonymized_entry['created_by'] = '0';
                }

                // Step 4 — persist to the database.
                $result  = GFAPI::update_entry($anonymized_entry);
                $success = ! is_wp_error($result) && $result === true;
                break;

            case 'delete_files':
                $form_obj = is_wp_error($form)
                    ? GFAPI::get_form(rgar($entry, 'form_id'))
                    : $form;

                if (is_wp_error($form_obj) || empty($form_obj['fields'])) {
                    $success = false;
                    break;
                }

                $files = self::delete_entry_files($entry, $form_obj);

                // Clear the file fields so the entry does not keep dead links.
                $updated_entry = $entry;
                foreach ($files['field_ids'] as $field_id) {
                    $updated_entry[(string) $field_id] = '';
                }
                $result = $files['field_ids'] ? GFAPI::update_entry($updated_entry) : true;

                foreach ($files['meta_keys'] as $meta_key) {
                    gform_delete_meta($entry_id, $meta_key);
                }

                if ($files['deleted'] || $files['failed'] || $files['missing']) {
                    GFAPI::add_note(
                        $entry_id,
                        0,
                        'GF Advanced Expiring Entries',
                        sprintf(
                            /* translators: 1: deleted files, 2: files that could not be deleted, 3: files not found on disk */
                            __('Uploaded files deleted on expiry: %1$d deleted, %2$d failed, %3$d not found.', 'gf-advanced-expiring-entries'),
                            $files['deleted'],
                            $files['failed'],
                            $files['missing']
                        )
                    );
                }

                $success = ! is_wp_error($result) && $result === true && 0 === $files['failed'];
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

        // Bust dashboard widget cache so new counts show immediately.
        GF_AEE_Dashboard::invalidate_cache();

        // Schedule post-expiry notifications if configured.
        self::maybe_schedule_post_notification($entry_id, $feed, $success, $entry);

        /**
         * Fires after an expiry action completes.
         */
        do_action('gf_aee_after_expiry_action', $entry_id, $action, $feed, $success);
    }

	/* ─── Post-expiry notification scheduling ─────────────────────────── */

    /**
     * Schedule a post-expiry notification if configured for the result type.
     *
     * @param int   $entry_id Entry ID.
     * @param array $feed     Feed configuration.
     * @param bool  $success  Whether the expiry action succeeded.
     * @param array $entry    The entry data captured before the action ran.
     */
    private static function maybe_schedule_post_notification($entry_id, $feed, $success, $entry)
    {
        $meta = rgar($feed, 'meta');
        $type = $success ? 'success' : 'fail';

        $enabled_key  = 'enable_post_notification_' . $type;
        $value_key    = 'post_notify_' . $type . '_value';
        $unit_key     = 'post_notify_' . $type . '_unit';

        if (empty($meta[$enabled_key])) {
            return;
        }

        $delay_value = absint(rgar($meta, $value_key, 0));
        $delay_unit  = rgar($meta, $unit_key, 'minutes');

        // If delay is 0, send immediately (schedule for now).
        $notify_ts = $delay_value > 0
            ? GF_AEE_Processor::apply_offset(time(), '+', $delay_value, $delay_unit)
            : time();

        // For destructive actions (trash/delete) the entry may be gone when the
        // cron fires, so snapshot the pre-action entry data so merge tags in the
        // notification can still be resolved.
        $action = rgar($meta, 'expiry_action', '');
        if (in_array($action, array('trash', 'delete'), true) && is_array($entry)) {
            $ttl = max(($notify_ts - time()) + HOUR_IN_SECONDS, HOUR_IN_SECONDS);
            set_transient('gf_aee_post_snap_' . $entry_id, $entry, $ttl);
        }

        GF_AEE_Scheduler::schedule_post_notification($entry_id, $notify_ts, $type);
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

    /**
     * Delete physical files attached to fileupload fields in an entry.
     *
     * Single-file and multi-file upload fields are both handled.
     * Uses wp_delete_file() so other plugins can hook into the
     * 'wp_delete_file' filter if needed.
     *
     * Files referenced by entry meta (e.g. the zip archive built by Gravity Wiz's
     * "Zip Uploaded Files" snippet, stored under the 'gw_zip' meta key) are deleted too.
     *
     * @param array $entry    GF entry array (before anonymization).
     * @param array $form_obj GF form array.
     * @return array{deleted:int, failed:int, missing:int, field_ids:int[], meta_keys:string[]}
     *               field_ids / meta_keys list the fields and meta whose files are all gone
     *               and can safely be cleared.
     */
    private static function delete_entry_files( array $entry, array $form_obj ): array {

        $report   = array( 'deleted' => 0, 'failed' => 0, 'missing' => 0, 'field_ids' => array(), 'meta_keys' => array() );
        $entry_id = (int) rgar( $entry, 'id' );

        foreach ( $form_obj['fields'] as $field ) {

            if ( $field->type !== 'fileupload' ) {
                continue;
            }

            $file_val = rgar( $entry, (string) $field->id );
            if ( empty( $file_val ) ) {
                continue;
            }

            if ( self::delete_file_urls( self::parse_file_urls( $file_val ), $entry_id, $report ) ) {
                $report['field_ids'][] = (int) $field->id;
            }
        }

        /**
         * Filter the entry meta keys holding URLs of files generated for the entry
         * (archives, exports…) that must be deleted along with the uploaded files.
         *
         * @param string[] $meta_keys Meta keys. Default: 'gw_zip' (Gravity Wiz Zip Uploaded Files).
         * @param array    $entry     GF entry.
         * @param array    $form_obj  GF form.
         */
        $meta_keys = (array) apply_filters( 'gf_aee_delete_files_meta_keys', array( 'gw_zip' ), $entry, $form_obj );

        foreach ( $meta_keys as $meta_key ) {
            $meta_val = gform_get_meta( $entry_id, $meta_key );
            if ( empty( $meta_val ) ) {
                continue;
            }

            $urls = is_array( $meta_val )
                ? array_filter( $meta_val, 'is_string' )
                : self::parse_file_urls( (string) $meta_val );

            if ( self::delete_file_urls( $urls, $entry_id, $report ) ) {
                $report['meta_keys'][] = (string) $meta_key;
            }
        }

        return $report;
    }

    /**
     * Delete a set of uploaded files and update the report counters.
     *
     * @param string[] $urls     File URLs.
     * @param int      $entry_id Entry ID.
     * @param array    $report   Report updated in place (deleted / failed / missing).
     * @return bool True when every file is now gone, so the value referencing them can be cleared.
     */
    private static function delete_file_urls( array $urls, int $entry_id, array &$report ): bool {

        $all_gone = ! empty( $urls );

        foreach ( $urls as $file_url ) {
            if ( empty( $file_url ) || ! is_string( $file_url ) ) {
                continue;
            }

            $file_path = self::get_upload_path( $file_url, $entry_id );

            if ( ! $file_path || ! is_file( $file_path ) ) {
                // Unresolvable URL or file already gone: keep the value so nothing is lost silently.
                $report['missing']++;
                $all_gone = false;
                self::addon_log( sprintf( 'File not found for %s (entry #%d)', $file_url, $entry_id ) );
                continue;
            }

            wp_delete_file( $file_path );

            if ( is_file( $file_path ) ) {
                $report['failed']++;
                $all_gone = false;
                self::addon_log( sprintf( 'Could not delete file %s (entry #%d)', $file_path, $entry_id ) );
            } else {
                $report['deleted']++;
                self::addon_log( sprintf( 'Deleted file %s (entry #%d)', $file_path, $entry_id ) );
            }
        }

        return $all_gone;
    }

    /**
     * Extract the file URLs stored in a File Upload field value.
     *
     * Multi-file values are a JSON array, but some entries (older ones, or values seen
     * through the REST API) hold a comma-separated list instead; both are accepted.
     *
     * @param string $file_val Raw field value.
     * @return string[]
     */
    private static function parse_file_urls( string $file_val ): array {

        $decoded = json_decode( $file_val, true );
        $urls    = is_array( $decoded )
            ? $decoded
            : preg_split( '/,(?=\s*https?:\/\/)/i', $file_val );

        return array_values( array_filter( array_map( 'trim', array_filter( (array) $urls, 'is_string' ) ) ) );
    }

    /**
     * Map an uploaded file URL to its absolute path, whatever the scheme or host it was stored with.
     *
     * @param string $file_url URL stored in the entry.
     * @param int    $entry_id Entry ID.
     * @return string Absolute path, or '' when the URL is not under the uploads directory.
     */
    private static function get_upload_path( string $file_url, int $entry_id ): string {

        if ( method_exists( 'GFFormsModel', 'get_physical_file_path' ) ) {
            $path = GFFormsModel::get_physical_file_path( $file_url, $entry_id );
            if ( $path && is_file( $path ) ) {
                return $path;
            }
        }

        // Fallback: compare URL paths so http/https or www/non-www differences do not matter.
        $upload_dir = wp_get_upload_dir();
        $base_path  = trailingslashit( (string) wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH ) );
        $url_path   = rawurldecode( (string) wp_parse_url( $file_url, PHP_URL_PATH ) );

        if ( 0 !== strpos( $url_path, $base_path ) ) {
            return '';
        }

        $relative = substr( $url_path, strlen( $base_path ) );
        if ( false !== strpos( $relative, '..' ) ) {
            return '';
        }

        return trailingslashit( $upload_dir['basedir'] ) . $relative;
    }
}
