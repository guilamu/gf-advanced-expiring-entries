<?php

/**
 * GF_AEE_Trash_Purge — Permanently deletes entries that have stayed in the trash
 * longer than a configurable number of days.
 *
 * Gravity Forms does not record when an entry is trashed (only the status column
 * changes), so the trash date is tracked in entry meta from the gform_update_status
 * action. Entries already in the trash when tracking starts get a date on the first
 * purge run, according to the "legacy" setting.
 */

defined('ABSPATH') || exit;

class GF_AEE_Trash_Purge
{

    const HOOK       = 'gf_aee_purge_trash';
    const META       = '_gf_aee_trashed_at';
    const BATCH_SIZE = 200;
    const TIME_LIMIT = 20; // Seconds per run; what is left is handled by a follow-up run.

    /**
     * Register listeners and keep the daily event in sync with the settings.
     */
    public static function init()
    {
        add_action('gform_update_status', array(__CLASS__, 'track_status'), 10, 3);
        add_action(self::HOOK, array(__CLASS__, 'run'));

        self::sync_schedule();
    }

    /**
     * Schedule the daily purge when enabled, remove it when disabled.
     */
    public static function sync_schedule()
    {
        if (self::get_settings()['enabled']) {
            if (! wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
            }
        } elseif (wp_next_scheduled(self::HOOK)) {
            self::unschedule_all();
        }
    }

    /**
     * Remember when an entry enters the trash; forget it when the entry leaves it.
     *
     * @param int    $entry_id Entry ID.
     * @param string $status   New status.
     * @param string $previous Previous status.
     */
    public static function track_status($entry_id, $status, $previous)
    {
        if ($status === 'trash') {
            gform_update_meta($entry_id, self::META, time());
        } elseif ($previous === 'trash') {
            gform_delete_meta($entry_id, self::META);
        }
    }

    /**
     * Read the purge settings straight from the option (same reason as GF_AEE_Scheduler::get_interval()).
     *
     * @return array{enabled:bool, days:int, legacy:string, excluded:int[]}
     */
    public static function get_settings()
    {
        $settings = get_option('gravityformsaddon_gf-advanced-expiring-entries_settings', array());
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $settings = is_array($settings) ? $settings : array();

        $days = absint(rgar($settings, 'trash_purge_days', 365));

        return array(
            'enabled'  => ! empty($settings['enable_trash_purge']),
            'days'     => $days > 0 ? $days : 365,
            'legacy'   => rgar($settings, 'trash_purge_legacy') === 'date_updated' ? 'date_updated' : 'now',
            'excluded' => array_values(array_filter(array_map('absint', preg_split('/[\s,;]+/', (string) rgar($settings, 'trash_purge_excluded_forms', ''))))),
        );
    }

    /**
     * Count what the next run would delete, per form, without changing anything.
     *
     * Untracked entries are counted as the next run would date them (legacy setting).
     *
     * @return array{total:int, untracked:int, by_form:array<int,int>}
     */
    public static function preview()
    {
        global $wpdb;

        $settings  = self::get_settings();
        $threshold = time() - $settings['days'] * DAY_IN_SECONDS;
        $legacy_ts = $settings['legacy'] === 'date_updated'
            ? 'UNIX_TIMESTAMP(e.date_updated)'
            : (string) time();

        // phpcs:disable WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.form_id,
                    SUM(CASE WHEN COALESCE(CAST(m.meta_value AS UNSIGNED), {$legacy_ts}) <= %d THEN 1 ELSE 0 END) AS due,
                    SUM(CASE WHEN m.meta_id IS NULL THEN 1 ELSE 0 END) AS untracked
             FROM " . GFFormsModel::get_entry_table_name() . " e
             LEFT JOIN " . GFFormsModel::get_entry_meta_table_name() . " m ON m.entry_id = e.id AND m.meta_key = %s
             WHERE e.status = 'trash'" . self::excluded_sql($settings) . "
             GROUP BY e.form_id",
            $threshold,
            self::META
        ));
        // phpcs:enable

        $report = array('total' => 0, 'untracked' => 0, 'by_form' => array());
        foreach ($rows as $row) {
            $report['untracked'] += (int) $row->untracked;
            if ((int) $row->due > 0) {
                $report['by_form'][(int) $row->form_id] = (int) $row->due;
                $report['total'] += (int) $row->due;
            }
        }
        arsort($report['by_form']);

        return $report;
    }

    /**
     * Daily run: date the untracked trashed entries, then delete the expired ones by batches.
     *
     * @return array{dated:int, deleted:int, failed:int, remaining:bool}
     */
    public static function run()
    {
        global $wpdb;

        $settings = self::get_settings();
        $report   = array('dated' => 0, 'deleted' => 0, 'failed' => 0, 'remaining' => false);

        if (! $settings['enabled']) {
            return $report;
        }

        $entry_table = GFFormsModel::get_entry_table_name();
        $meta_table  = GFFormsModel::get_entry_meta_table_name();

        // 1. Date the entries trashed before tracking existed (or by code bypassing gform_update_status).
        // phpcs:disable WordPress.DB.PreparedSQL
        $untracked = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, e.form_id, e.date_updated FROM {$entry_table} e
             LEFT JOIN {$meta_table} m ON m.entry_id = e.id AND m.meta_key = %s
             WHERE e.status = 'trash' AND m.meta_id IS NULL",
            self::META
        ));

        foreach ($untracked as $row) {
            $ts = time();
            if ($settings['legacy'] === 'date_updated' && $row->date_updated) {
                $ts = (int) strtotime($row->date_updated . ' UTC');
            }
            gform_update_meta((int) $row->id, self::META, $ts, (int) $row->form_id);
            $report['dated']++;
        }

        // 2. Delete the entries trashed for longer than the retention period.
        $threshold = time() - $settings['days'] * DAY_IN_SECONDS;
        $started   = time();
        $timed_out = false;

        do {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT e.id FROM {$entry_table} e
                 INNER JOIN {$meta_table} m ON m.entry_id = e.id AND m.meta_key = %s
                 WHERE e.status = 'trash' AND CAST(m.meta_value AS UNSIGNED) <= %d" . self::excluded_sql($settings) . "
                 ORDER BY e.id LIMIT %d",
                self::META,
                $threshold,
                self::BATCH_SIZE
            ));
            // phpcs:enable

            foreach ($ids as $entry_id) {
                $form_id = (int) $wpdb->get_var($wpdb->prepare("SELECT form_id FROM {$entry_table} WHERE id = %d", $entry_id)); // phpcs:ignore WordPress.DB.PreparedSQL
                $result  = GFAPI::delete_entry($entry_id); // Also deletes uploaded files and entry meta.
                $success = ! is_wp_error($result);

                $success ? $report['deleted']++ : $report['failed']++;
                GF_AEE_Log::write(
                    $entry_id,
                    $form_id,
                    0,
                    'purge_trash',
                    $success,
                    $success
                        ? sprintf(
                            /* translators: %d = number of days */
                            __('Permanently deleted after more than %d days in the trash.', 'gf-advanced-expiring-entries'),
                            $settings['days']
                        )
                        : $result->get_error_message()
                );

                if (time() - $started >= self::TIME_LIMIT) {
                    $timed_out = true;
                    break 2;
                }
            }
            // Stop on failure: the failed entry would be selected again and again.
        } while (count($ids) === self::BATCH_SIZE && $report['failed'] === 0);

        // More to delete: continue in a couple of minutes instead of waiting a whole day.
        if ($timed_out || (count($ids) === self::BATCH_SIZE && $report['failed'] === 0)) {
            $report['remaining'] = true;
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::HOOK);
        }

        if (class_exists('GF_AEE_Dashboard')) {
            GF_AEE_Dashboard::invalidate_cache();
        }

        return $report;
    }

    /**
     * SQL fragment excluding the forms listed in the settings.
     */
    private static function excluded_sql(array $settings)
    {
        return $settings['excluded']
            ? ' AND e.form_id NOT IN (' . implode(',', array_map('absint', $settings['excluded'])) . ')'
            : '';
    }

    /**
     * Remove scheduled events (settings off, deactivation, uninstall).
     */
    public static function unschedule_all()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }
}
