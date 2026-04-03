<?php

/**
 * GF_AEE_Scheduler — wp_cron-based scheduling for expiry checks.
 *
 * Follows the exact same pattern as gp-notification-scheduler:
 * setup_cron() is called from init(), registers the custom schedule,
 * the event, and the listener all in one method.
 */

defined('ABSPATH') || exit;

class GF_AEE_Scheduler
{

    const HOOK_EXPIRY_CHECK      = 'gf_aee_run_expiry_check';
    const HOOK_PRE_NOTIFICATION  = 'gf_aee_send_pre_notification';
    const HOOK_POST_NOTIFICATION = 'gf_aee_send_post_notification';
    const DEFAULT_INTERVAL       = 1 * MINUTE_IN_SECONDS;

    /**
     * Get the configured check interval in seconds.
     *
     * Reads the option directly from the database instead of calling
     * gf_aee()->get_plugin_settings(), because this method can be
     * invoked during init() before settings helpers are fully
     * available.
     */
    public static function get_interval()
    {
        $settings = get_option('gravityformsaddon_gf-advanced-expiring-entries_settings', array());
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (is_array($settings) && ! empty($settings['check_interval'])) {
            $minutes = absint($settings['check_interval']);
            if ($minutes > 0) {
                return $minutes * MINUTE_IN_SECONDS;
            }
        }
        return self::DEFAULT_INTERVAL;
    }

    /**
     * Bootstrap cron: register custom schedule, schedule the event, add listener.
     * Called from init() to avoid triggering translation loading too early
     * (wp_schedule_event → wp_get_schedules → cron_schedules filter → __()).
     *
     * Self-healing: if the scheduled event is overdue (cron not spawning),
     * we reschedule for the future and run the check inline with a
     * transient-based lock so it only fires once per interval.
     */
    public static function setup_cron()
    {
        // 1. Register the custom cron schedule.
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));

        // 2. Register the listeners (must be in place before any cron fires).
        add_action(self::HOOK_EXPIRY_CHECK,     array(__CLASS__, 'run_expiry_check'));
        add_action(self::HOOK_PRE_NOTIFICATION, array(__CLASS__, 'run_pre_notification'), 10, 1);
        add_action(self::HOOK_POST_NOTIFICATION, array(__CLASS__, 'run_post_notification'), 10, 2);

        // 3. Schedule or self-heal.
        $next = wp_next_scheduled(self::HOOK_EXPIRY_CHECK);

        if (! $next) {
            // Not scheduled at all — schedule now.
            wp_schedule_event(time(), 'gf_aee_check_interval', self::HOOK_EXPIRY_CHECK);
            if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] setup_cron: Scheduled new cron event.'); }
            return;
        }

        if ($next > time()) {
            // Scheduled and not yet due — nothing to do.
            return;
        }

        // Event is overdue — cron spawning is likely disabled or broken.
        // Reschedule for the future so the event doesn't stay permanently stale.
        $interval = self::get_interval();
        wp_clear_scheduled_hook(self::HOOK_EXPIRY_CHECK);
        wp_schedule_event(time() + $interval, 'gf_aee_check_interval', self::HOOK_EXPIRY_CHECK);

        // Run the expiry check inline, but only once per interval (transient lock).
        if (false === get_transient('gf_aee_expiry_lock')) {
            set_transient('gf_aee_expiry_lock', 1, $interval);
            if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] setup_cron: Cron was overdue by ' . (time() - $next) . 's — running expiry check inline.'); }
            self::run_expiry_check();
        }
    }

    /**
     * Schedule a single pre-expiry notification for one entry.
     */
    public static function schedule_pre_notification($entry_id, $notify_ts)
    {
        wp_schedule_single_event($notify_ts, self::HOOK_PRE_NOTIFICATION, array((int) $entry_id));
    }

    /**
     * Schedule a single post-expiry notification for one entry.
     *
     * @param int    $entry_id  Entry ID.
     * @param int    $notify_ts Timestamp at which to send.
     * @param string $type      'success' or 'fail'.
     */
    public static function schedule_post_notification($entry_id, $notify_ts, $type)
    {
        wp_schedule_single_event($notify_ts, self::HOOK_POST_NOTIFICATION, array((int) $entry_id, sanitize_key($type)));
    }

    /**
     * Remove all scheduled events (deactivation / uninstall).
     */
    public static function unschedule_all()
    {
        wp_clear_scheduled_hook(self::HOOK_EXPIRY_CHECK);
    }

    /**
     * Reschedule: clear the existing event and re-register so a new interval
     * takes effect immediately. Called when plugin settings are saved.
     */
    public static function reschedule()
    {
        wp_clear_scheduled_hook(self::HOOK_EXPIRY_CHECK);
        wp_schedule_event(time(), 'gf_aee_check_interval', self::HOOK_EXPIRY_CHECK);
    }

	/* ─── Runners ─────────────────────────────────────────────────────── */

    /**
     * Main expiry check: query all active entries whose expiry_ts has passed.
     */
    public static function run_expiry_check()
    {
        global $wpdb;

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: STARTED at ' . gmdate('Y-m-d H:i:s')); }

        $table = GFFormsModel::get_entry_meta_table_name();
        $entry_table = GFFormsModel::get_entry_table_name();
        $now   = time(); // UTC.

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: now=' . $now . ' (' . gmdate('Y-m-d H:i:s', $now) . '), meta_table=' . $table . ', entry_table=' . $entry_table); }

        // phpcs:disable WordPress.DB.PreparedSQL
        $sql = $wpdb->prepare(
            "SELECT em_ts.entry_id
			 FROM {$table} AS em_ts
			 INNER JOIN {$table} AS em_st
			   ON em_ts.entry_id = em_st.entry_id
			   AND em_st.meta_key = %s
			 INNER JOIN {$entry_table} AS e
			   ON em_ts.entry_id = e.id
			   AND e.status = 'active'
			 WHERE (
			     (
			       em_st.meta_value = %s
			       AND em_ts.meta_key = %s
			       AND CAST( em_ts.meta_value AS UNSIGNED ) <= %d
			     )
			     OR
			     (
			       em_st.meta_value = %s
			       AND em_ts.meta_key = %s
			       AND CAST( em_ts.meta_value AS UNSIGNED ) <= %d
			     )
			 )
			 LIMIT 100",
            GF_AEE_Meta::STATUS,
            GF_AEE_Meta::STATUS_ACTIVE,
            GF_AEE_Meta::EXPIRY_TS,
            $now,
            GF_AEE_Meta::STATUS_EXTENDED,
            GF_AEE_Meta::OVERRIDE_TS,
            $now
        );

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check SQL: ' . $sql); }

        $entry_ids = $wpdb->get_col($sql);
        // phpcs:enable

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: found ' . count($entry_ids) . ' entries: ' . implode(', ', $entry_ids)); }

        if (empty($entry_ids)) {
            if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: No expired entries found. DONE.'); }
            return;
        }

        foreach ($entry_ids as $entry_id) {
            if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: Processing entry #' . $entry_id); }
            GF_AEE_Expiry_Runner::run((int) $entry_id);
        }

        if ( GF_AEE_DEBUG ) { error_log('[GF-AEE] run_expiry_check: FINISHED.'); }
    }

    /**
     * Fire the pre-expiry notification for a single entry.
     *
     * @param int $entry_id Entry ID.
     */
    public static function run_pre_notification($entry_id)
    {

        $entry_id = (int) $entry_id;
        $status   = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::STATUS);

        // Only fire if still active.
        if ($status !== GF_AEE_Meta::STATUS_ACTIVE) {
            return;
        }

        // Already notified?
        if (GF_AEE_Meta::get($entry_id, GF_AEE_Meta::NOTIFIED)) {
            return;
        }

        $feed_id = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::FEED_ID);
        if (! $feed_id) {
            return;
        }

        $addon = gf_aee();
        $feed  = $addon ? $addon->get_feed($feed_id) : null;
        if (! $feed) {
            return;
        }

        $meta            = rgar($feed, 'meta');
        $notification_id = rgar($meta, 'pre_notify_notification_id');
        $form_id         = rgar($feed, 'form_id');

        if ($notification_id && $form_id) {
            $form  = GFAPI::get_form($form_id);
            $entry = GFAPI::get_entry($entry_id);

            if (! is_wp_error($entry) && ! is_wp_error($form)) {
                GFCommon::send_notification(rgar(rgar($form, 'notifications', array()), $notification_id), $form, $entry);
                GF_AEE_Meta::set($entry_id, GF_AEE_Meta::NOTIFIED, 1);
                GF_AEE_Meta::log_action($entry_id, 'pre_notification', true, sprintf(
                    /* translators: %s = notification ID */
                    __('Notification %s sent.', 'gf-advanced-expiring-entries'),
                    $notification_id
                ));
            }
        }
    }

    /**
     * Fire a post-expiry notification for a single entry.
     *
     * @param int    $entry_id Entry ID.
     * @param string $type     'success' or 'fail'.
     */
    public static function run_post_notification($entry_id, $type = 'success')
    {

        $entry_id = (int) $entry_id;
        $type     = sanitize_key($type);

        // Determine the meta key tracking whether this notification was already sent.
        $notified_key = $type === 'fail'
            ? GF_AEE_Meta::POST_NOTIFIED_FAIL
            : GF_AEE_Meta::POST_NOTIFIED_SUCCESS;

        if (GF_AEE_Meta::get($entry_id, $notified_key)) {
            return;
        }

        $feed_id = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::FEED_ID);
        if (! $feed_id) {
            return;
        }

        $addon = gf_aee();
        $feed  = $addon ? $addon->get_feed($feed_id) : null;
        if (! $feed) {
            return;
        }

        $meta            = rgar($feed, 'meta');
        $notification_id = rgar($meta, 'post_notify_' . $type . '_notification_id');
        $form_id         = rgar($feed, 'form_id');

        if ($notification_id && $form_id) {
            $form  = GFAPI::get_form($form_id);
            $entry = GFAPI::get_entry($entry_id);

            // Fallback: if the entry was trashed/deleted, use the snapshot
            // stored at scheduling time so merge tags still resolve.
            if (is_wp_error($entry)) {
                $snapshot = get_transient('gf_aee_post_snap_' . $entry_id);
                if ($snapshot && is_array($snapshot)) {
                    $entry = $snapshot;
                }
            }
            // Clean up the transient regardless.
            delete_transient('gf_aee_post_snap_' . $entry_id);

            if (! is_wp_error($entry) && ! is_wp_error($form)) {
                $notification = rgar(rgar($form, 'notifications', array()), $notification_id);
                if ($notification) {
                    GFCommon::send_notification($notification, $form, $entry);
                    GF_AEE_Meta::set($entry_id, $notified_key, 1);
                    GF_AEE_Meta::log_action($entry_id, 'post_notification_' . $type, true, sprintf(
                        /* translators: %s = notification ID */
                        __('Post-expiry notification (%s) sent.', 'gf-advanced-expiring-entries'),
                        $type
                    ));
                }
            }
        }
    }

	/* ─── Utilities ───────────────────────────────────────────────────── */

    /**
     * Register custom cron interval.
     */
    public static function add_cron_schedule($schedules)
    {
        if (! isset($schedules['gf_aee_check_interval'])) {
            $interval = self::get_interval();
            $schedules['gf_aee_check_interval'] = array(
                'interval' => $interval,
                'display'  => sprintf('Every %d minute(s) (GF AEE)', round($interval / MINUTE_IN_SECONDS)),
            );
        }
        return $schedules;
    }
}
