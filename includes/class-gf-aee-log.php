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
     * Render the log table.
     *
     * @param string $page_base_url  The base URL for this tab (used for filters & pagination).
     */
    public static function render_page($page_base_url = '')
    {
        if (! $page_base_url) {
            $page_base_url = admin_url('admin.php?page=gf_settings&subview=gf-advanced-expiring-entries');
        }

        $per_page = 50;
        $page_num = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $offset   = ($page_num - 1) * $per_page;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filters = array(
            'form_id' => isset($_GET['gf_aee_form'])    ? absint($_GET['gf_aee_form'])              : 0,
            'action'  => isset($_GET['gf_aee_action'])  ? sanitize_key($_GET['gf_aee_action'])      : '',
            'success' => isset($_GET['gf_aee_success']) ? sanitize_key($_GET['gf_aee_success'])     : 'all',
        );

        $total       = self::count_items($filters);
        $items       = self::get_items($per_page, $offset, $filters);
        $total_pages = max(1, (int) ceil($total / $per_page));

        // Determine which entry IDs on this page still exist in GF (single IN query).
        $existing_entry_ids = array();
        if (! empty($items)) {
            global $wpdb;
            $ids          = array_unique(array_map('intval', wp_list_pluck($items, 'entry_id')));
            $placeholders = implode(',', $ids);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $existing_entry_ids = array_map('intval', $wpdb->get_col("SELECT id FROM {$wpdb->prefix}gf_entry WHERE id IN ({$placeholders})"));
        }

        $forms = GFAPI::get_forms(true, false, 'title', 'ASC');

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

        // Base URL with current filter args preserved for pagination.
        $base_url = add_query_arg(
            array_filter(array(
                'gf_aee_form'    => $filters['form_id'] ? $filters['form_id'] : '',
                'gf_aee_action'  => $filters['action'],
                'gf_aee_success' => $filters['success'] !== 'all' ? $filters['success'] : '',
            )),
            $page_base_url
        );

        // Parse the base URL to carry its query args as hidden fields in the GET form.
        $parsed     = wp_parse_url($page_base_url);
        $form_action = esc_url(admin_url($parsed['path'] ?? 'admin.php'));
        $base_args   = array();
        if (! empty($parsed['query'])) {
            wp_parse_str($parsed['query'], $base_args);
        }

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

            <form method="get" action="<?php echo $form_action; ?>">
                <?php foreach ($base_args as $key => $val) : ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                <?php endforeach; ?>

                <div class="gf-aee-log-filters">

                        <select name="gf_aee_form">
                            <option value=""><?php esc_html_e('All forms', 'gf-advanced-expiring-entries'); ?></option>
                            <?php foreach ($forms as $form) : ?>
                                <option value="<?php echo esc_attr($form['id']); ?>" <?php selected($filters['form_id'], $form['id']); ?>>
                                    <?php echo esc_html($form['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="gf_aee_action">
                            <option value=""><?php esc_html_e('All actions', 'gf-advanced-expiring-entries'); ?></option>
                            <?php foreach ($action_slugs as $slug) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['action'], $slug); ?>>
                                    <?php echo esc_html(isset($action_labels[$slug]) ? $action_labels[$slug] : ucfirst($slug)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="gf_aee_success">
                            <option value="all"  <?php selected($filters['success'], 'all'); ?>><?php esc_html_e('Success + Failed', 'gf-advanced-expiring-entries'); ?></option>
                            <option value="1"    <?php selected($filters['success'], '1');   ?>><?php esc_html_e('Success only', 'gf-advanced-expiring-entries'); ?></option>
                            <option value="0"    <?php selected($filters['success'], '0');   ?>><?php esc_html_e('Failed only', 'gf-advanced-expiring-entries'); ?></option>
                        </select>

                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'gf-advanced-expiring-entries'); ?>">

                        <?php if ($filters['form_id'] || $filters['action'] || $filters['success'] !== 'all') : ?>
                            <a href="<?php echo esc_url($page_base_url); ?>" class="button"><?php esc_html_e('Reset', 'gf-advanced-expiring-entries'); ?></a>
                        <?php endif; ?>

                </div><!-- .gf-aee-log-filters -->

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
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row['executed_at']))); ?></td>
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
                                <td><?php echo esc_html(isset($action_labels[$row['action']]) ? $action_labels[$row['action']] : $row['action']); ?></td>
                                <td>
                                    <?php if ($row['success']) : ?>
                                        <span style="color:#00a32a">&#10003; <?php esc_html_e('OK', 'gf-advanced-expiring-entries'); ?></span>
                                    <?php else : ?>
                                        <span style="color:#d63638">&#10007; <?php esc_html_e('Fail', 'gf-advanced-expiring-entries'); ?></span>
                                    <?php endif; ?>
                                </td>
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
                                'base'    => add_query_arg('paged', '%#%', $base_url),
                                'format'  => '',
                                'current' => $page_num,
                                'total'   => $total_pages,
                            )));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            </form>
        </div><!-- .gf-aee-log-wrap -->
        <?php
    }
}
