<?php

/**
 * GF_AEE_Processor — Computes and stores the expiry timestamp on entry submission.
 */

defined('ABSPATH') || exit;

class GF_AEE_Processor
{

    /**
     * Process a single feed for a submitted entry.
     *
     * @param array $feed  The feed configuration.
     * @param array $entry The submitted entry.
     * @param array $form  The form object.
     */
    public static function process($feed, $entry, $form)
    {

        $meta      = $feed['meta'];
        $expiry_ts = null;

        /* ── Fixed date ───────────────────────────────────────────────── */
        if (rgar($meta, 'expiry_type') === 'fixed') {

            $fixed_date = rgar($meta, 'fixed_expiry_date');
            $expiry_ts  = $fixed_date ? strtotime($fixed_date) : null;

            /* ── Dynamic (based on a date field) ──────────────────────────── */
        } elseif (rgar($meta, 'expiry_type') === 'dynamic') {

            $field_id   = rgar($meta, 'date_field_id');
            $date_value = rgar($entry, $field_id);

            // Fallback when the source date field is empty.
            if (empty($date_value)) {
                $fallback = rgar($meta, 'empty_date_fallback', 'skip');

                switch ($fallback) {
                    case 'skip':
                        // Do not set expiry; entry stays forever.
                        return;

                    case 'entry_date':
                        $date_value = rgar($entry, 'date_created');
                        break;

                    case 'fixed_fallback':
                        $date_value = rgar($meta, 'fallback_fixed_date');
                        break;
                }

                if (empty($date_value)) {
                    return; // still empty after fallback.
                }
            }

            $base_ts = strtotime($date_value);

            if (! $base_ts) {
                return; // un-parseable date.
            }

            // Apply offset.
            if (rgar($meta, 'use_offset')) {
                $direction = rgar($meta, 'offset_direction', '+');
                $value     = absint(rgar($meta, 'offset_value', 0));
                $unit      = rgar($meta, 'offset_unit', 'days');

                $base_ts = self::apply_offset($base_ts, $direction, $value, $unit);
            }

            // Snap-to.
            $snap = rgar($meta, 'snap_to', '');
            if ($snap === 'start') {
                $base_ts = mktime(0, 0, 0, (int) gmdate('n', $base_ts), (int) gmdate('j', $base_ts), (int) gmdate('Y', $base_ts));
            } elseif ($snap === 'end') {
                $base_ts = mktime(23, 59, 59, (int) gmdate('n', $base_ts), (int) gmdate('j', $base_ts), (int) gmdate('Y', $base_ts));
            }

            $expiry_ts = $base_ts;

            /* ── Entry date (creation or last updated) ────────────────────── */
        } elseif (rgar($meta, 'expiry_type') === 'entry_meta') {

            $source     = rgar($meta, 'entry_meta_source', 'date_created');
            $date_value = rgar($entry, $source);

            if (empty($date_value)) {
                return; // should not happen, but guard.
            }

            $base_ts = strtotime($date_value);

            if (! $base_ts) {
                return;
            }

            // Apply offset.
            if (rgar($meta, 'use_offset')) {
                $direction = rgar($meta, 'offset_direction', '+');
                $value     = absint(rgar($meta, 'offset_value', 0));
                $unit      = rgar($meta, 'offset_unit', 'days');

                $base_ts = self::apply_offset($base_ts, $direction, $value, $unit);
            }

            // Snap-to.
            $snap = rgar($meta, 'snap_to', '');
            if ($snap === 'start') {
                $base_ts = mktime(0, 0, 0, (int) gmdate('n', $base_ts), (int) gmdate('j', $base_ts), (int) gmdate('Y', $base_ts));
            } elseif ($snap === 'end') {
                $base_ts = mktime(23, 59, 59, (int) gmdate('n', $base_ts), (int) gmdate('j', $base_ts), (int) gmdate('Y', $base_ts));
            }

            $expiry_ts = $base_ts;
        }

        if (! $expiry_ts || $expiry_ts <= 0) {
            return;
        }

        /**
         * Allow third parties to override the computed expiry timestamp.
         *
         * @param int   $expiry_ts Computed expiry Unix timestamp.
         * @param array $entry     The submitted entry.
         * @param array $feed      The feed configuration.
         * @param array $form      The form object.
         */
        $expiry_ts = apply_filters('gf_aee_computed_expiry_ts', $expiry_ts, $entry, $feed, $form);

        // Persist.
        $entry_id = rgar($entry, 'id');
        $feed_id  = rgar($feed, 'id');
        GF_AEE_Meta::set_expiry($entry_id, $expiry_ts, $feed_id);

        // Bust dashboard widget cache so new counts show immediately.
        GF_AEE_Dashboard::invalidate_cache();

        // Schedule pre-expiry notification if configured.
        if (rgar($meta, 'enable_pre_notification')) {
            $pre_value = absint(rgar($meta, 'pre_notify_value', 0));
            $pre_unit  = rgar($meta, 'pre_notify_unit', 'days');

            if ($pre_value > 0) {
                $notify_ts = self::apply_offset($expiry_ts, '-', $pre_value, $pre_unit);
                // Only schedule if the notification date is still in the future.
                if ($notify_ts > time()) {
                    GF_AEE_Scheduler::schedule_pre_notification($entry_id, $notify_ts);
                }
            }
        }
    }

    /**
     * Apply a time offset to a base timestamp.
     *
     * @param int    $base_ts   Base Unix timestamp.
     * @param string $direction '+' or '-'.
     * @param int    $value     Offset amount.
     * @param string $unit      minutes|hours|days|weeks|months.
     *
     * @return int Modified timestamp.
     */
    public static function apply_offset($base_ts, $direction, $value, $unit)
    {

        if ($unit === 'months') {
            return strtotime("{$direction}{$value} months", $base_ts);
        }

        $map = array(
            'minutes' => MINUTE_IN_SECONDS,
            'hours'   => HOUR_IN_SECONDS,
            'days'    => DAY_IN_SECONDS,
            'weeks'   => WEEK_IN_SECONDS,
        );

        $multiplier = isset($map[$unit]) ? $map[$unit] : DAY_IN_SECONDS;
        $sign       = ($direction === '+') ? 1 : -1;

        return $base_ts + $sign * $value * $multiplier;
    }
}
