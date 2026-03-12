<?php

/**
 * GF_AEE_Log — Central expiry event log table.
 *
 * Every expiry action (execute, skip, dry-run, fail) is written to
 * wp_gf_aee_expiry_log so administrators can audit all events in one place.
 * Accessible via Forms › Expiry Log in wp-admin.
 */

defined('ABSPATH') || exit;

class GF_AEE_Log
{

    const TABLE_SUFFIX = 'gf_aee_expiry_log';

    /* ── Schema ─────────────────────────────────────────────────────── */

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table()
    {
        global $wpdb;

        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id    BIGINT UNSIGNED NOT NULL,
            form_id     MEDIUMINT UNSIGNED NOT NULL,
            feed_id     MEDIUMINT UNSIGNED NOT NULL,
            action      VARCHAR(64) NOT NULL DEFAULT '',
            success     TINYINT(1) NOT NULL DEFAULT 0,
            message     TEXT NOT NULL,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY form_id (form_id),
            KEY executed_at (executed_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ── Write ──────────────────────────────────────────────────────── */

    /**
     * Record one expiry event.
     *
     * @param int    $entry_id
     * @param int    $form_id
     * @param int    $feed_id
     * @param string $action   Slug (trash, delete, skip, …).
     * @param bool   $success
     * @param string $message  Human-readable detail.
     */
    public static function write($entry_id, $form_id, $feed_id, $action, $success, $message = '')
    {
        global $wpdb;

        $wpdb->insert(
            self::get_table_name(),
            array(
                'entry_id'    => (int) $entry_id,
                'form_id'     => (int) $form_id,
                'feed_id'     => (int) $feed_id,
                'action'      => (string) $action,
                'success'     => $success ? 1 : 0,
                'message'     => (string) $message,
                'executed_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('%d', '%d', '%d', '%s', '%d', '%s', '%s')
        );
    }

    /* ── Read ───────────────────────────────────────────────────────── */

    /**
     * Count log rows matching optional filters.
     *
     * @param array $filters {form_id, action, success}.
     * @return int
     */
    public static function count_items($filters = array())
    {
        global $wpdb;
        $table = self::get_table_name();
        $where = self::build_where($filters);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");
    }

    /**
     * Fetch a page of log rows.
     *
     * @param int   $per_page
     * @param int   $offset
     * @param array $filters
     * @return array Array of assoc arrays.
     */
    public static function get_items($per_page = 50, $offset = 0, $filters = array())
    {
        global $wpdb;
        $table = self::get_table_name();
        $where = self::build_where($filters);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table}{$where} ORDER BY executed_at DESC LIMIT %d OFFSET %d", $per_page, $offset),
            ARRAY_A
        );
    }

    /**
     * Build a safe WHERE clause (each condition individually prepared).
     *
     * @param array $filters
     * @return string  SQL fragment, e.g. " WHERE form_id = 3 AND success = 1".
     */
    private static function build_where($filters)
    {
        global $wpdb;

        $conditions = array();

        if (! empty($filters['form_id'])) {
            $conditions[] = $wpdb->prepare('form_id = %d', (int) $filters['form_id']);
        }
        if (! empty($filters['action'])) {
            $conditions[] = $wpdb->prepare('action = %s', sanitize_key($filters['action']));
        }
        if (isset($filters['success']) && $filters['success'] !== 'all' && $filters['success'] !== '') {
            $conditions[] = $wpdb->prepare('success = %d', (int) $filters['success']);
        }

        return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    }

    /* ── Admin page ─────────────────────────────────────────────────── */

    /**
     * Render the log page (filters + results).
     *
     * @param string $page_base_url  The base URL for this tab.
     */
    public static function render_page($page_base_url = '')
    {
        if (! $page_base_url) {
            $page_base_url = admin_url('admin.php?page=gf_settings&subview=gf-advanced-expiring-entries');
        }

        $per_page = 10;
        $page_num = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filters = array(
            'form_id' => isset($_GET['gf_aee_form'])    ? absint($_GET['gf_aee_form'])              : 0,
            'action'  => isset($_GET['gf_aee_action'])  ? sanitize_key($_GET['gf_aee_action'])      : '',
            'success' => isset($_GET['gf_aee_success']) ? sanitize_key($_GET['gf_aee_success'])     : 'all',
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $period = isset($_GET['gf_aee_period']) ? sanitize_key($_GET['gf_aee_period']) : 'all';
        if (! in_array($period, array('all', 'past', 'future'), true)) {
            $period = 'all';
        }

        // Only show forms that have at least one Expiring Entries feed.
        $all_forms = GFAPI::get_forms(true, false, 'title', 'ASC');
        $addon     = gf_aee();
        $forms     = array();
        if ($addon) {
            foreach ($all_forms as $form) {
                if (! empty($addon->get_feeds(rgar($form, 'id')))) {
                    $forms[] = $form;
                }
            }
        } else {
            $forms = $all_forms;
        }

        $action_slugs = array('trash', 'delete', 'change_status', 'update_field', 'webhook', 'notification', 'skip');

        $action_labels = array(
            'trash'         => __('Trash', 'gf-advanced-expiring-entries'),
            'delete'        => __('Delete', 'gf-advanced-expiring-entries'),
            'change_status' => __('Change Status', 'gf-advanced-expiring-entries'),
            'update_field'  => __('Update Field', 'gf-advanced-expiring-entries'),
            'webhook'       => __('Webhook', 'gf-advanced-expiring-entries'),
            'notification'  => __('Notification', 'gf-advanced-expiring-entries'),
            'skip'          => __('Skipped', 'gf-advanced-expiring-entries'),
        );

        ?>
        <style>
            .gf-aee-log-wrap .gf-aee-log-filters {
                display: flex;
                gap: 6px;
                align-items: center;
                margin-bottom: 6px;
            }
            .gf-aee-log-wrap .gf-aee-count {
                display: block;
                margin: 0 0 10px;
                color: #50575e;
                font-style: italic;
            }
        </style>
        <div class="gf-aee-log-wrap">

            <div class="gf-aee-log-filters">

                <select id="gf-aee-log-form">
                    <option value=""><?php esc_html_e('All forms', 'gf-advanced-expiring-entries'); ?></option>
                    <?php foreach ($forms as $form) : ?>
                        <option value="<?php echo esc_attr($form['id']); ?>" <?php selected($filters['form_id'], $form['id']); ?>>
                            <?php echo esc_html($form['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="gf-aee-log-action">
                    <option value=""><?php esc_html_e('All actions', 'gf-advanced-expiring-entries'); ?></option>
                    <?php foreach ($action_slugs as $slug) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['action'], $slug); ?>>
                            <?php echo esc_html(isset($action_labels[$slug]) ? $action_labels[$slug] : ucfirst($slug)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="gf-aee-log-success">
                    <option value="all"  <?php selected($filters['success'], 'all'); ?>><?php esc_html_e('Success + Failed', 'gf-advanced-expiring-entries'); ?></option>
                    <option value="1"    <?php selected($filters['success'], '1');   ?>><?php esc_html_e('Success only', 'gf-advanced-expiring-entries'); ?></option>
                    <option value="0"    <?php selected($filters['success'], '0');   ?>><?php esc_html_e('Failed only', 'gf-advanced-expiring-entries'); ?></option>
                </select>

                <select id="gf-aee-log-period">
                    <option value="all"    <?php selected($period, 'all'); ?>><?php esc_html_e('All expirations', 'gf-advanced-expiring-entries'); ?></option>
                    <option value="past"   <?php selected($period, 'past'); ?>><?php esc_html_e('Past expirations', 'gf-advanced-expiring-entries'); ?></option>
                    <option value="future" <?php selected($period, 'future'); ?>><?php esc_html_e('Future expirations', 'gf-advanced-expiring-entries'); ?></option>
                </select>

            </div><!-- .gf-aee-log-filters -->

            <div id="gf-aee-log-results">
                <?php self::render_results($filters, $page_num, $per_page, $period); ?>
            </div>

        </div><!-- .gf-aee-log-wrap -->
        <?php
    }

    /**
     * Render the log results (count + table + pagination).
     *
     * Used by render_page() for the initial load and by the AJAX handler
     * for live filtering.
     *
     * @param array $filters   {form_id, action, success}.
     * @param int   $page_num  Current page (1-based).
     * @param int   $per_page  Items per page.
     */
    public static function render_results($filters = array(), $page_num = 1, $per_page = 10, $period = 'all')
    {
        $offset = ($page_num - 1) * $per_page;

        // ── Collect rows depending on period ──────────────────────────
        $past_rows   = array();
        $future_rows = array();

        if ($period !== 'future') {
            $past_total = self::count_items($filters);
            $raw_past   = ($period === 'past')
                ? self::get_items($per_page, $offset, $filters)
                : self::get_items(PHP_INT_MAX, 0, $filters); // fetch all for merge
            $past_rows  = self::normalize_past_rows($raw_past);
        }

        if ($period !== 'past') {
            $future_total = self::count_future_items($filters);
            $raw_future   = ($period === 'future')
                ? self::get_future_items($per_page, $offset, $filters)
                : self::get_future_items(PHP_INT_MAX, 0, $filters);
            $future_rows  = self::normalize_future_rows($raw_future);
        }

        // ── Merge / paginate ──────────────────────────────────────────
        if ($period === 'past') {
            $total = $past_total;
            $items = $past_rows;
        } elseif ($period === 'future') {
            $total = $future_total;
            $items = $future_rows;
        } else {
            // "all": merge both, sort by sort_ts descending, then paginate.
            $merged = array_merge($future_rows, $past_rows);
            usort($merged, function ($a, $b) {
                return $b['sort_ts'] - $a['sort_ts'];
            });
            $total = count($merged);
            $items = array_slice($merged, $offset, $per_page);
        }

        $total_pages = max(1, (int) ceil($total / $per_page));

        // ── Resolve entry links ──────────────────────────────────────
        $existing_entry_ids = array();
        if (! empty($items)) {
            global $wpdb;
            $ids          = array_unique(array_map('intval', wp_list_pluck($items, 'entry_id')));
            $placeholders = implode(',', $ids);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $existing_entry_ids = array_map('intval', $wpdb->get_col("SELECT id FROM {$wpdb->prefix}gf_entry WHERE id IN ({$placeholders})"));
        }

        ?>
        <span class="gf-aee-count">
            <?php
            /* translators: %d = total number of log rows */
            printf(esc_html(_n('%d item', '%d items', $total, 'gf-advanced-expiring-entries')), (int) $total);
            ?>
        </span>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:165px"><?php esc_html_e('Date / Time', 'gf-advanced-expiring-entries'); ?></th>
                    <th style="width:70px"><?php esc_html_e('Entry', 'gf-advanced-expiring-entries'); ?></th>
                    <th style="width:70px"><?php esc_html_e('Form', 'gf-advanced-expiring-entries'); ?></th>
                    <th style="width:70px"><?php esc_html_e('Feed', 'gf-advanced-expiring-entries'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Action', 'gf-advanced-expiring-entries'); ?></th>
                    <th style="width:80px"><?php esc_html_e('Result', 'gf-advanced-expiring-entries'); ?></th>
                    <th><?php esc_html_e('Message', 'gf-advanced-expiring-entries'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No log entries found.', 'gf-advanced-expiring-entries'); ?></td>
                    </tr>
                <?php else : foreach ($items as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['sort_ts'])); ?></td>
                        <td>
                            <?php
                            if (in_array((int) $row['entry_id'], $existing_entry_ids, true)) {
                                $entry_url = admin_url('admin.php?page=gf_entries&view=entry&id=' . $row['form_id'] . '&lid=' . $row['entry_id']);
                                echo '<a href="' . esc_url($entry_url) . '">#' . esc_html($row['entry_id']) . '</a>';
                            } else {
                                echo '#' . esc_html($row['entry_id']);
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($row['form_id']); ?></td>
                        <td><?php echo esc_html($row['feed_id']); ?></td>
                        <td><?php echo esc_html($row['action_label']); ?></td>
                        <td><?php echo $row['result_html']; // Already escaped in normalizers. ?></td>
                        <td><?php echo esc_html($row['message']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links(array(
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page_num,
                        'total'   => $total_pages,
                    )));
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Normalize past log rows into unified format.
     *
     * @param array $rows Raw rows from get_items().
     * @return array
     */
    private static function normalize_past_rows($rows)
    {
        $action_labels = array(
            'trash'         => __('Trash', 'gf-advanced-expiring-entries'),
            'delete'        => __('Delete', 'gf-advanced-expiring-entries'),
            'change_status' => __('Change Status', 'gf-advanced-expiring-entries'),
            'update_field'  => __('Update Field', 'gf-advanced-expiring-entries'),
            'webhook'       => __('Webhook', 'gf-advanced-expiring-entries'),
            'notification'  => __('Notification', 'gf-advanced-expiring-entries'),
            'skip'          => __('Skipped', 'gf-advanced-expiring-entries'),
        );

        $items = array();
        foreach ($rows as $row) {
            $result_html = $row['success']
                ? '<span style="color:#00a32a">&#10003; ' . esc_html__('OK', 'gf-advanced-expiring-entries') . '</span>'
                : '<span style="color:#d63638">&#10007; ' . esc_html__('Fail', 'gf-advanced-expiring-entries') . '</span>';

            $items[] = array(
                'sort_ts'      => strtotime($row['executed_at']),
                'entry_id'     => $row['entry_id'],
                'form_id'      => $row['form_id'],
                'feed_id'      => $row['feed_id'],
                'action_label' => isset($action_labels[$row['action']]) ? $action_labels[$row['action']] : $row['action'],
                'result_html'  => $result_html,
                'message'      => $row['message'],
            );
        }
        return $items;
    }

    /**
     * Normalize future expiry rows into unified format.
     *
     * @param array $rows Raw rows from get_future_items().
     * @return array
     */
    private static function normalize_future_rows($rows)
    {
        // Build feed → action lookup.
        $feed_actions = array();
        $addon = gf_aee();
        if ($addon && ! empty($rows)) {
            $feed_ids = array_unique(array_filter(array_map('intval', wp_list_pluck($rows, 'feed_id'))));
            foreach ($feed_ids as $fid) {
                $feed = $addon->get_feed($fid);
                $feed_actions[$fid] = $feed ? rgars($feed, 'meta/expiry_action') : '';
            }
        }

        $action_labels = array(
            'trash'         => __('Trash', 'gf-advanced-expiring-entries'),
            'delete'        => __('Delete', 'gf-advanced-expiring-entries'),
            'change_status' => __('Change Status', 'gf-advanced-expiring-entries'),
            'update_field'  => __('Update Field', 'gf-advanced-expiring-entries'),
            'webhook'       => __('Webhook', 'gf-advanced-expiring-entries'),
            'notification'  => __('Notification', 'gf-advanced-expiring-entries'),
        );

        $items = array();
        foreach ($rows as $row) {
            $fid         = (int) $row['feed_id'];
            $action_slug = isset($feed_actions[$fid]) ? $feed_actions[$fid] : '';

            if ($row['aee_status'] === GF_AEE_Meta::STATUS_EXTENDED) {
                $result_html = '<span class="gf-aee-badge gf-aee-badge--extended">' . esc_html__('Extended', 'gf-advanced-expiring-entries') . '</span>';
            } else {
                $result_html = '<span class="gf-aee-badge gf-aee-badge--active">' . esc_html__('Active', 'gf-advanced-expiring-entries') . '</span>';
            }

            $items[] = array(
                'sort_ts'      => (int) $row['effective_expiry_ts'],
                'entry_id'     => $row['entry_id'],
                'form_id'      => $row['form_id'],
                'feed_id'      => $row['feed_id'],
                'action_label' => isset($action_labels[$action_slug]) ? $action_labels[$action_slug] : $action_slug,
                'result_html'  => $result_html,
                'message'      => '',
            );
        }
        return $items;
    }

    /* ── Future expirations ─────────────────────────────────────────── */

    /**
     * Count entries with a future effective expiry timestamp.
     *
     * @param array $filters {form_id}.
     * @return int
     */
    public static function count_future_items($filters = array())
    {
        global $wpdb;
        $meta_table  = GFFormsModel::get_entry_meta_table_name();
        $entry_table = GFFormsModel::get_entry_table_name();
        $now         = time();

        $form_clause = '';
        if (! empty($filters['form_id'])) {
            $form_clause = $wpdb->prepare(' AND e.form_id = %d', (int) $filters['form_id']);
        }

        // phpcs:disable WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT em_ts.entry_id)
             FROM {$meta_table} AS em_ts
             INNER JOIN {$meta_table} AS em_st
               ON em_ts.entry_id = em_st.entry_id
               AND em_st.meta_key = %s
               AND em_st.meta_value IN (%s, %s)
             LEFT JOIN {$meta_table} AS em_ov
               ON em_ts.entry_id = em_ov.entry_id
               AND em_ov.meta_key = %s
             INNER JOIN {$entry_table} AS e
               ON em_ts.entry_id = e.id
             WHERE em_ts.meta_key = %s
               AND e.status = 'active'
               AND CAST(COALESCE(em_ov.meta_value, em_ts.meta_value) AS UNSIGNED) > %d
               {$form_clause}",
            GF_AEE_Meta::STATUS,
            GF_AEE_Meta::STATUS_ACTIVE,
            GF_AEE_Meta::STATUS_EXTENDED,
            GF_AEE_Meta::OVERRIDE_TS,
            GF_AEE_Meta::EXPIRY_TS,
            $now
        ));
        // phpcs:enable
    }

    /**
     * Fetch a page of entries with a future effective expiry timestamp.
     *
     * @param int   $per_page
     * @param int   $offset
     * @param array $filters {form_id}.
     * @return array
     */
    public static function get_future_items($per_page = 10, $offset = 0, $filters = array())
    {
        global $wpdb;
        $meta_table  = GFFormsModel::get_entry_meta_table_name();
        $entry_table = GFFormsModel::get_entry_table_name();
        $now         = time();

        $form_clause = '';
        if (! empty($filters['form_id'])) {
            $form_clause = $wpdb->prepare(' AND e.form_id = %d', (int) $filters['form_id']);
        }

        // phpcs:disable WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT em_ts.entry_id,
                    e.form_id,
                    em_fd.meta_value AS feed_id,
                    em_st.meta_value AS aee_status,
                    CAST(COALESCE(em_ov.meta_value, em_ts.meta_value) AS UNSIGNED) AS effective_expiry_ts
             FROM {$meta_table} AS em_ts
             INNER JOIN {$meta_table} AS em_st
               ON em_ts.entry_id = em_st.entry_id
               AND em_st.meta_key = %s
               AND em_st.meta_value IN (%s, %s)
             LEFT JOIN {$meta_table} AS em_ov
               ON em_ts.entry_id = em_ov.entry_id
               AND em_ov.meta_key = %s
             LEFT JOIN {$meta_table} AS em_fd
               ON em_ts.entry_id = em_fd.entry_id
               AND em_fd.meta_key = %s
             INNER JOIN {$entry_table} AS e
               ON em_ts.entry_id = e.id
             WHERE em_ts.meta_key = %s
               AND e.status = 'active'
               AND CAST(COALESCE(em_ov.meta_value, em_ts.meta_value) AS UNSIGNED) > %d
               {$form_clause}
             ORDER BY effective_expiry_ts ASC
             LIMIT %d OFFSET %d",
            GF_AEE_Meta::STATUS,
            GF_AEE_Meta::STATUS_ACTIVE,
            GF_AEE_Meta::STATUS_EXTENDED,
            GF_AEE_Meta::OVERRIDE_TS,
            GF_AEE_Meta::FEED_ID,
            GF_AEE_Meta::EXPIRY_TS,
            $now,
            $per_page,
            $offset
        ), ARRAY_A);
        // phpcs:enable
    }

}
