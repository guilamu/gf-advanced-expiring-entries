<?php

/**
 * GF_AEE_Dashboard — Dashboard widget showing expiry summary per form.
 */

defined('ABSPATH') || exit;

class GF_AEE_Dashboard
{

    const TRANSIENT_KEY = 'gf_aee_dashboard_data';
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    /**
     * Register the dashboard widget.
     */
    public static function register_widget()
    {
        wp_add_dashboard_widget(
            'gf_aee_dashboard_widget',
            esc_html__('Gravity Forms — Expiring Entries', 'gf-advanced-expiring-entries'),
            array(__CLASS__, 'render')
        );
    }

    /**
     * Render the dashboard widget.
     */
    public static function render()
    {
        $data = self::get_data();

        if (empty($data)) {
            echo '<p>' . esc_html__('No forms with expiry feeds configured.', 'gf-advanced-expiring-entries') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Form', 'gf-advanced-expiring-entries') . '</th>';
        echo '<th>' . esc_html__('Active', 'gf-advanced-expiring-entries') . '</th>';
        echo '<th>' . esc_html__('Expiring &lt; 7d', 'gf-advanced-expiring-entries') . '</th>';
        echo '<th>' . esc_html__('Expired', 'gf-advanced-expiring-entries') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['form_title']) . '</td>';
            echo '<td>' . (int) $row['active'] . '</td>';
            echo '<td>' . (int) $row['expiring_soon'] . '</td>';
            echo '<td>' . (int) $row['expired'] . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Get cached dashboard data.
     *
     * @return array
     */
    private static function get_data()
    {

        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $data  = array();
        $addon = gf_aee();
        if (! $addon) {
            return $data;
        }

        $forms = GFAPI::get_forms();

        foreach ($forms as $form) {
            $form_id = rgar($form, 'id');
            $feeds   = $addon->get_feeds($form_id);

            if (empty($feeds)) {
                continue; // only show forms that have at least one AEE feed.
            }

            $data[] = array(
                'form_title'    => rgar($form, 'title'),
                'active'        => self::count_by_status($form_id, GF_AEE_Meta::STATUS_ACTIVE),
                'expiring_soon' => self::count_expiring_soon($form_id),
                'expired'       => self::count_by_status($form_id, GF_AEE_Meta::STATUS_EXPIRED),
            );
        }

        set_transient(self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL);

        return $data;
    }

    /**
     * Count entries with a specific AEE status for a form.
     */
    private static function count_by_status($form_id, $status)
    {
        global $wpdb;
        $table = GFFormsModel::get_entry_meta_table_name();
        $entry_table = GFFormsModel::get_entry_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT( DISTINCT em.entry_id )
			 FROM {$table} AS em
			 INNER JOIN {$entry_table} AS e ON em.entry_id = e.id
			 WHERE em.meta_key = %s
			   AND em.meta_value = %s
			   AND e.form_id = %d
			   AND e.status = 'active'",
            GF_AEE_Meta::STATUS,
            $status,
            $form_id
        ));
        // phpcs:enable
    }

    /**
     * Count entries expiring within 7 days for a form.
     */
    private static function count_expiring_soon($form_id)
    {
        global $wpdb;
        $table       = GFFormsModel::get_entry_meta_table_name();
        $entry_table = GFFormsModel::get_entry_table_name();
        $now         = time();
        $seven_days  = $now + (7 * DAY_IN_SECONDS);

        // phpcs:disable WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT( DISTINCT em_ts.entry_id )
			 FROM {$table} AS em_ts
			 INNER JOIN {$table} AS em_st
			   ON em_ts.entry_id = em_st.entry_id
			   AND em_st.meta_key = %s
			   AND em_st.meta_value = %s
			 INNER JOIN {$entry_table} AS e
			   ON em_ts.entry_id = e.id
			 WHERE em_ts.meta_key = %s
			   AND CAST( em_ts.meta_value AS UNSIGNED ) > %d
			   AND CAST( em_ts.meta_value AS UNSIGNED ) <= %d
			   AND e.form_id = %d
			   AND e.status = 'active'",
            GF_AEE_Meta::STATUS,
            GF_AEE_Meta::STATUS_ACTIVE,
            GF_AEE_Meta::EXPIRY_TS,
            $now,
            $seven_days,
            $form_id
        ));
        // phpcs:enable
    }
}
