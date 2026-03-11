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
            if ($fixed_date) {
                // User-facing date → interpret in the WP timezone.
                $dt        = date_create($fixed_date, wp_timezone());
                $expiry_ts = $dt ? $dt->getTimestamp() : null;
            }

            /* ── Dynamic (based on a date field) ──────────────────────────── */
        } elseif (rgar($meta, 'expiry_type') === 'dynamic') {

            $field_id   = rgar($meta, 'date_field_id');
            $date_value = rgar($entry, $field_id);
            $is_utc     = false;

            // Fallback when the source date field is empty.
            if (empty($date_value)) {
                $fallback = rgar($meta, 'empty_date_fallback', 'skip');

                switch ($fallback) {
                    case 'skip':
                        // Do not set expiry; entry stays forever.
                        return;

                    case 'entry_date':
                        $date_value = rgar($entry, 'date_created');
                        $is_utc     = true; // GF stores date_created in UTC.
                        break;

                    case 'fixed_fallback':
                        $date_value = rgar($meta, 'fallback_fixed_date');
                        break;
                }

                if (empty($date_value)) {
                    return; // still empty after fallback.
                }
            }

            // User-facing dates are interpreted in the WP timezone;
            // GF-internal dates (date_created fallback) are already UTC.
            if ($is_utc) {
                $base_ts = strtotime($date_value);
            } else {
                $dt      = date_create($date_value, wp_timezone());
                $base_ts = $dt ? $dt->getTimestamp() : false;
            }

            if (! $base_ts) {
                return; // un-parseable date.
            }

            $expiry_ts = self::apply_time_and_offset($base_ts, $meta);

            /* ── Entry date (creation or last updated) ────────────────────── */
        } elseif (rgar($meta, 'expiry_type') === 'entry_meta') {

            $source     = rgar($meta, 'entry_meta_source', 'date_created');
            $date_value = rgar($entry, $source);

            if (empty($date_value)) {
                return; // should not happen, but guard.
            }

            // date_created / date_updated are stored in UTC by GF.
            $base_ts = strtotime($date_value);

            if (! $base_ts) {
                return;
            }

            $expiry_ts = self::apply_time_and_offset($base_ts, $meta);
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
     * Apply time-of-day override and time offset to a base timestamp.
     *
     * Time override is applied first (in the WP timezone) so the offset
     * adjusts from the anchored time. Supports legacy `snap_to` values.
     *
     * @param int   $base_ts Base Unix timestamp.
     * @param array $meta    Feed meta.
     *
     * @return int Modified timestamp.
     */
    private static function apply_time_and_offset($base_ts, $meta)
    {
        // Resolve time override: new `expiry_time` or legacy `snap_to`.
        $expiry_time = rgar($meta, 'expiry_time', '');
        if (empty($expiry_time)) {
            $snap = rgar($meta, 'snap_to', '');
            if ($snap === 'start') {
                $expiry_time = '00:00';
            } elseif ($snap === 'end') {
                $expiry_time = '23:59';
            }
        }

        // 1. Set time of day (in WP timezone).
        if ($expiry_time && preg_match('/^(\d{1,2}):(\d{2})$/', $expiry_time, $m)) {
            $tz = wp_timezone();
            $dt = (new \DateTimeImmutable('@' . $base_ts))->setTimezone($tz);
            $dt = $dt->setTime((int) $m[1], (int) $m[2], 0);
            $base_ts = $dt->getTimestamp();
        }

        // 2. Apply offset.
        $offset_val = absint(rgar($meta, 'offset_value', 0));
        if ($offset_val > 0) {
            $direction = rgar($meta, 'offset_direction', '+');
            $unit      = rgar($meta, 'offset_unit', 'minutes');
            $base_ts   = self::apply_offset($base_ts, $direction, $offset_val, $unit);
        }

        return $base_ts;
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
