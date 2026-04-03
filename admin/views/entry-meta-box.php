<?php

/**
 * Entry meta-box — rendered in the entry detail sidebar.
 *
 * Variables available:
 *   $entry_id       (int)
 *   $status         (string|null)
 *   $expiry_ts      (int|null)
 *   $override       (int|null)
 *   $action_log     (array)
 *   $feed_summaries (array)  — each element: ['name' => string, 'summary' => string]
 */

defined('ABSPATH') || exit;

$status_labels = array(
    GF_AEE_Meta::STATUS_ACTIVE   => __('Active', 'gf-advanced-expiring-entries'),
    GF_AEE_Meta::STATUS_EXPIRED  => __('Expired', 'gf-advanced-expiring-entries'),
    GF_AEE_Meta::STATUS_EXTENDED => __('Extended', 'gf-advanced-expiring-entries'),
    GF_AEE_Meta::STATUS_EXEMPT   => __('Exempt', 'gf-advanced-expiring-entries'),
);

$status_label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst((string) $status);
$status_class = 'gf-aee-badge gf-aee-badge--' . esc_attr($status ?: 'unknown');

$date_format  = get_option('date_format') . ' ' . get_option('time_format');
$expiry_date  = $expiry_ts ? wp_date($date_format, $expiry_ts) : '—';
$override_val = $override ? wp_date('Y-m-d\TH:i', $override) : '';
?>
<div class="postbox gf-aee-entry-meta-box">
    <h3 class="hndle">
        <span><?php esc_html_e('Entry Expiration', 'gf-advanced-expiring-entries'); ?></span>
    </h3>
    <div class="inside">
        <?php wp_nonce_field('gf_aee_entry_override', 'gf_aee_nonce'); ?>

        <table class="gf-aee-meta-table">
            <tr>
                <td><strong><?php esc_html_e('Status', 'gf-advanced-expiring-entries'); ?></strong></td>
                <td><span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Expires', 'gf-advanced-expiring-entries'); ?></strong></td>
                <td><?php echo esc_html($expiry_date); ?></td>
            </tr>
            <?php if ($override) : ?>
                <tr>
                    <td><strong><?php esc_html_e('Override', 'gf-advanced-expiring-entries'); ?></strong></td>
                    <td><?php echo esc_html(wp_date($date_format, $override)); ?></td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if (! empty($feed_summaries)) : ?>
            <hr />
            <p><strong><?php esc_html_e('Scheduled Action', 'gf-advanced-expiring-entries'); ?></strong></p>
            <?php foreach ($feed_summaries as $fs) : ?>
                <p class="gf-aee-feed-summary-line" style="margin:4px 0;">
                    <?php echo esc_html($fs['summary']); ?>
                </p>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr />

        <!-- Override date-time picker -->
        <p>
            <label for="gf_aee_override_date"><strong><?php esc_html_e('Set Override Date', 'gf-advanced-expiring-entries'); ?></strong></label><br />
            <input type="datetime-local" id="gf_aee_override_date" name="gf_aee_override_date"
                value="<?php echo esc_attr($override_val); ?>" style="width:100%;" />
        </p>

        <?php if ($override) : ?>
            <p>
                <label>
                    <input type="checkbox" name="gf_aee_remove_override" value="1" />
                    <?php esc_html_e('Remove override', 'gf-advanced-expiring-entries'); ?>
                </label>
            </p>
        <?php endif; ?>

        <!-- Exempt toggle -->
        <p>
            <label>
                <input type="checkbox" name="gf_aee_exempt" value="1"
                    <?php checked($status, GF_AEE_Meta::STATUS_EXEMPT); ?> />
                <?php esc_html_e('Mark as Exempt (skip all processing)', 'gf-advanced-expiring-entries'); ?>
            </label>
        </p>

        <?php if (! empty($action_log)) : ?>
            <hr />
            <p><strong><?php esc_html_e('Action Log (last 3)', 'gf-advanced-expiring-entries'); ?></strong></p>
            <ul class="gf-aee-action-log">
                <?php
                $recent = array_slice($action_log, -3);
                foreach (array_reverse($recent) as $log_entry) :
                    $icon = $log_entry['success'] ? '✔' : '✖';
                    $ts   = isset($log_entry['timestamp']) ? wp_date($date_format, $log_entry['timestamp']) : '';
                ?>
                    <li>
                        <span class="gf-aee-log-icon"><?php echo esc_html($icon); ?></span>
                        <code><?php echo esc_html($log_entry['action']); ?></code>
                        <small><?php echo esc_html($ts); ?></small>
                        <?php if (! empty($log_entry['message'])) : ?>
                            <br /><small><?php echo esc_html($log_entry['message']); ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>