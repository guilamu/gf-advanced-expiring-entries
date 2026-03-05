<?php

/**
 * GF_AEE_Meta — Entry meta helper (read / write / delete).
 *
 * Wraps gform_entry_meta functions so we can change key prefixes in one place.
 */

defined('ABSPATH') || exit;

class GF_AEE_Meta
{

    /* ── Meta key constants ───────────────────────────────────────────── */
    const EXPIRY_TS        = '_gf_aee_expiry_ts';
    const FEED_ID          = '_gf_aee_feed_id';
    const STATUS           = '_gf_aee_status';
    const OVERRIDE_TS      = '_gf_aee_override_ts';
    const NOTIFIED         = '_gf_aee_notified';
    const ACTION_LOG       = '_gf_aee_action_log';
    const POST_NOTIFIED_SUCCESS = '_gf_aee_post_notified_success';
    const POST_NOTIFIED_FAIL    = '_gf_aee_post_notified_fail';

    /* ── Status constants ─────────────────────────────────────────────── */
    const STATUS_ACTIVE    = 'active';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_EXTENDED  = 'extended';
    const STATUS_EXEMPT    = 'exempt';

	/* ── Generic helpers ──────────────────────────────────────────────── */

    /**
     * Set a single meta value for an entry.
     */
    public static function set($entry_id, $key, $value)
    {
        gform_update_meta($entry_id, $key, $value);
    }

    /**
     * Get a single meta value for an entry.
     */
    public static function get($entry_id, $key)
    {
        return gform_get_meta($entry_id, $key);
    }

    /**
     * Delete a single meta value for an entry.
     */
    public static function delete($entry_id, $key)
    {
        gform_delete_meta($entry_id, $key);
    }

	/* ── Convenience setters ──────────────────────────────────────────── */

    /**
     * Write the full set of expiry meta when an entry is first processed.
     */
    public static function set_expiry($entry_id, $expiry_ts, $feed_id)
    {
        self::set($entry_id, self::EXPIRY_TS, (int) $expiry_ts);
        self::set($entry_id, self::FEED_ID,   (int) $feed_id);
        self::set($entry_id, self::STATUS,     self::STATUS_ACTIVE);
        self::set($entry_id, self::NOTIFIED,   0);
    }

    /**
     * Mark an entry as expired.
     */
    public static function mark_expired($entry_id)
    {
        self::set($entry_id, self::STATUS, self::STATUS_EXPIRED);
    }

    /**
     * Mark an entry as exempt (skip all processing).
     */
    public static function mark_exempt($entry_id)
    {
        self::set($entry_id, self::STATUS, self::STATUS_EXEMPT);
    }

    /**
     * Set a manual override timestamp.
     */
    public static function set_override($entry_id, $override_ts)
    {
        self::set($entry_id, self::OVERRIDE_TS, (int) $override_ts);
        self::set($entry_id, self::STATUS, self::STATUS_EXTENDED);
    }

    /**
     * Remove the manual override.
     */
    public static function remove_override($entry_id)
    {
        self::delete($entry_id, self::OVERRIDE_TS);
        self::set($entry_id, self::STATUS, self::STATUS_ACTIVE);
    }

	/* ── Action log ───────────────────────────────────────────────────── */

    /**
     * Append an entry to the action log (JSON-encoded array).
     */
    public static function log_action($entry_id, $action, $success, $message = '')
    {
        $log = self::get($entry_id, self::ACTION_LOG);
        $log = $log ? json_decode($log, true) : array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'timestamp' => time(),
            'action'    => $action,
            'success'   => (bool) $success,
            'message'   => $message,
        );

        self::set($entry_id, self::ACTION_LOG, wp_json_encode($log));
    }

    /**
     * Return parsed action log array.
     */
    public static function get_action_log($entry_id)
    {
        $log = self::get($entry_id, self::ACTION_LOG);
        $log = $log ? json_decode($log, true) : array();
        return is_array($log) ? $log : array();
    }

	/* ── Effective expiry timestamp ───────────────────────────────────── */

    /**
     * Returns the effective expiry timestamp, considering override.
     */
    public static function get_effective_expiry($entry_id)
    {
        $override = self::get($entry_id, self::OVERRIDE_TS);
        if (! empty($override)) {
            return (int) $override;
        }
        $ts = self::get($entry_id, self::EXPIRY_TS);
        return $ts ? (int) $ts : null;
    }
}
