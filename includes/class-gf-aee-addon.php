<?php

/**
 * GF_AEE_Addon — Core Feed Add-On class.
 *
 * Extends GFFeedAddOn and wires everything together:
 * feed settings, entry processing, entry list column,
 * entry detail meta-box, plugin settings (dry-run, retroactive tool),
 * and dashboard widget.
 */

defined('ABSPATH') || exit;

class GF_AEE_Addon extends GFFeedAddOn
{

    protected $_version                  = GF_AEE_VERSION;
    protected $_min_gravityforms_version = '2.7';
    protected $_slug                     = 'gf-advanced-expiring-entries';
    protected $_path                     = 'gf-advanced-expiring-entries/gf-advanced-expiring-entries.php';
    protected $_full_path                = GF_AEE_PLUGIN_FILE;
    protected $_title                    = 'GF Advanced Expiring Entries';
    protected $_short_title              = 'Expiring Entries';
    protected $_url                      = '';
    protected $_supports_feed_ordering   = true;

    /**
     * Capabilities required by GF to display menus and settings.
     *
     * Without these, the Members plugin (or any plugin filtering user_has_cap)
     * may deny access because GF looks up capabilities dynamically and
     * undeclared caps can be treated as non-existent.
     */
    protected $_capabilities             = array( 'gravityforms_edit_forms' );
    protected $_capabilities_settings_page = array( 'gravityforms_edit_forms' );
    protected $_capabilities_form_settings = array( 'gravityforms_edit_forms' );
    protected $_capabilities_uninstall     = array( 'gravityforms_uninstall' );

    private static $_instance = null;

    /* ─── Singleton ───────────────────────────────────────────────────── */

    public static function get_instance()
    {
        if (null === self::$_instance) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /* ─── Init ────────────────────────────────────────────────────────── */

    /**
     * Early init — fires before init(). Used to register wp_cron events
     * so they are ready before any entry processing occurs.
     */
    public function pre_init()
    {
        parent::pre_init();

        if ($this->is_gravityforms_supported()) {
            GF_AEE_Scheduler::setup_cron();
        }
    }

    public function init()
    {
        parent::init();

        // Register custom notification event so users can create notifications
        // dedicated to expiry (won't fire on form submission).
        add_filter('gform_notification_events', array($this, 'add_notification_events'), 10, 2);

        // Dashboard widget.
        add_action('wp_dashboard_setup', array('GF_AEE_Dashboard', 'register_widget'));

        // Entry list: add "Expires" column.
        add_filter('gform_entry_list_columns', array($this, 'add_expiry_column'), 10, 2);

        // Entry detail sidebar: meta box.
        add_action('gform_entry_detail_sidebar_middle', array($this, 'render_entry_meta_box'), 10, 2);

        // Save overrides from entry detail page.
        add_action('gform_after_update_entry', array($this, 'save_entry_override'), 10, 2);

        // Dry-run banner.
        add_action('admin_notices', array($this, 'maybe_show_dry_run_notice'));
    }

    /**
     * Add a custom "Expiring Entries" notification event to every form.
     *
     * This allows users to create GF notifications specifically for this plugin
     * (pre-expiry, post-expiry, expiry action) that will never fire on form
     * submission or any other standard GF event.
     *
     * @param array $events Existing notification events.
     * @param array $form   The current form.
     * @return array
     */
    public function add_notification_events($events, $form)
    {
        $events['gf_aee_expiry'] = esc_html__('Expiring Entries', 'gf-advanced-expiring-entries');
        return $events;
    }

    /* ─── Feed settings ───────────────────────────────────────────────── */

    public function feed_settings_fields()
    {
        return GF_AEE_Feed_Settings::get_fields($this);
    }

    /**
     * After plugin settings are saved, reschedule the recurring action
     * so the new interval takes effect immediately.
     */
    public function update_plugin_settings($settings)
    {
        parent::update_plugin_settings($settings);

        // Clear existing schedule and re-register with the new interval.
        GF_AEE_Scheduler::reschedule();
    }

    public function feed_list_columns()
    {
        return array(
            'feed_name'     => esc_html__('Name', 'gf-advanced-expiring-entries'),
            'expiry_type'   => esc_html__('Expiry Type', 'gf-advanced-expiring-entries'),
            'expiry_action' => esc_html__('Action', 'gf-advanced-expiring-entries'),
        );
    }

    /**
     * Feed list column values.
     */
    public function get_column_value_expiry_type($feed)
    {
        $type = rgars($feed, 'meta/expiry_type');
        $labels = array(
            'fixed'      => esc_html__('Fixed', 'gf-advanced-expiring-entries'),
            'dynamic'    => esc_html__('Dynamic', 'gf-advanced-expiring-entries'),
            'entry_meta' => esc_html__('Entry Date', 'gf-advanced-expiring-entries'),
        );
        return isset($labels[$type]) ? $labels[$type] : esc_html($type);
    }

    public function get_column_value_expiry_action($feed)
    {
        $actions = array(
            'trash'         => __('Trash', 'gf-advanced-expiring-entries'),
            'delete'        => __('Delete', 'gf-advanced-expiring-entries'),
            'change_status' => __('Change Status', 'gf-advanced-expiring-entries'),
            'update_field'  => __('Update Field', 'gf-advanced-expiring-entries'),
            'webhook'       => __('Webhook', 'gf-advanced-expiring-entries'),
            'notification'  => __('Notification', 'gf-advanced-expiring-entries'),
        );
        $action = rgars($feed, 'meta/expiry_action');
        return esc_html(isset($actions[$action]) ? $actions[$action] : $action);
    }

    public function can_duplicate_feed($feed_id)
    {
        return true;
    }

    public function feed_settings_title()
    {
        $feed = $this->get_feed($this->get_current_feed_id());
        $name = rgars($feed, 'meta/feed_name');
        $base = esc_html__('Expiry Feed', 'gf-advanced-expiring-entries');
        return $name ? sprintf('%s: %s', $base, $name) : sprintf('%s %s', esc_html__('New', 'gf-advanced-expiring-entries'), $base);
    }

    /* ─── Process feed on submission ──────────────────────────────────── */

    public function process_feed($feed, $entry, $form)
    {
        GF_AEE_Processor::process($feed, $entry, $form);
    }

	/* ─── Entry list column ───────────────────────────────────────────── */

    /**
     * Register our entry meta so GF knows about the "Expires" column.
     */
    public function get_entry_meta($entry_meta, $form_id = 0)
    {
        $entry_meta[GF_AEE_Meta::EXPIRY_TS] = array(
            'label'                      => esc_html__('Expires', 'gf-advanced-expiring-entries'),
            'is_numeric'                 => true,
            'is_default_column'          => false,
            'update_entry_meta_callback' => null,
            'filter'                     => array(
                'operators' => array('is', 'isnot', '>', '<'),
            ),
        );
        return $entry_meta;
    }

    /**
     * Add an "Expires" column to the entry list, before the last (cogwheel) column.
     */
    public function add_expiry_column($columns, $form_id)
    {
        $keys   = array_keys($columns);
        $values = array_values($columns);

        // Insert before the last column (the gear/edit icon).
        $pos = max(0, count($keys) - 1);
        array_splice($keys, $pos, 0, array(GF_AEE_Meta::EXPIRY_TS));
        array_splice($values, $pos, 0, array(esc_html__('Expires', 'gf-advanced-expiring-entries')));

        return array_combine($keys, $values);
    }

    /**
     * Render the column value (called via gform_entries_column_filter).
     */
    public function init_admin()
    {
        parent::init_admin();

        add_filter('gform_entries_column_filter', array($this, 'render_expiry_column_value'), 10, 5);
        add_action('admin_enqueue_scripts', array($this, 'maybe_localize_settings_script'), 20);
    }

    public function render_expiry_column_value($value, $form_id, $field_id, $entry, $query_string)
    {
        if ($field_id !== GF_AEE_Meta::EXPIRY_TS) {
            return $value;
        }

        $entry_id  = rgar($entry, 'id');
        $status    = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::STATUS);
        $expiry_ts = GF_AEE_Meta::get_effective_expiry($entry_id);
        $now       = time();

        if ($status === GF_AEE_Meta::STATUS_EXEMPT) {
            return '<span class="gf-aee-badge gf-aee-badge--exempt">' . esc_html__('Exempt', 'gf-advanced-expiring-entries') . '</span>';
        }

        if ($status === GF_AEE_Meta::STATUS_EXPIRED) {
            $date_str = $expiry_ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expiry_ts) : '';
            return '<span class="gf-aee-badge gf-aee-badge--expired">' . esc_html__('Expired', 'gf-advanced-expiring-entries') . '</span> ' . esc_html($date_str);
        }

        if (! $expiry_ts) {
            return '—';
        }

        $override  = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::OVERRIDE_TS);
        $date_str  = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expiry_ts);

        if ($override) {
            return '<span class="gf-aee-badge gf-aee-badge--override">✎</span> ' . esc_html($date_str);
        }

        $three_days = $now + (3 * DAY_IN_SECONDS);
        if ($expiry_ts <= $three_days) {
            return '<span class="gf-aee-badge gf-aee-badge--warning">' . esc_html($date_str) . '</span>';
        }

        return '<span class="gf-aee-badge gf-aee-badge--ok">' . esc_html($date_str) . '</span>';
    }

    /* ─── Entry Detail Meta Box ───────────────────────────────────────── */

    public function render_entry_meta_box($form, $entry)
    {
        $entry_id   = rgar($entry, 'id');
        $status     = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::STATUS);
        $expiry_ts  = GF_AEE_Meta::get_effective_expiry($entry_id);
        $override   = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::OVERRIDE_TS);
        $action_log = GF_AEE_Meta::get_action_log($entry_id);

        // Only show the box if entry has expiry data.
        if (! $status && ! $expiry_ts) {
            return;
        }

        include GF_AEE_PLUGIN_DIR . 'admin/views/entry-meta-box.php';
    }

    public function save_entry_override($form, $entry_id)
    {

        if (! isset($_POST['gf_aee_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gf_aee_nonce'])), 'gf_aee_entry_override')) {
            return;
        }

        // Exempt toggle.
        if (! empty($_POST['gf_aee_exempt'])) {
            GF_AEE_Meta::mark_exempt($entry_id);
        } else {
            // Was exempt? Revert to active.
            $current = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::STATUS);
            if ($current === GF_AEE_Meta::STATUS_EXEMPT) {
                GF_AEE_Meta::set($entry_id, GF_AEE_Meta::STATUS, GF_AEE_Meta::STATUS_ACTIVE);
            }
        }

        // Override date.
        if (! empty($_POST['gf_aee_override_date'])) {
            $override_raw = sanitize_text_field(wp_unslash($_POST['gf_aee_override_date']));
            $override_dt  = date_create($override_raw, wp_timezone());
            $override_ts  = $override_dt ? $override_dt->getTimestamp() : false;
            if ($override_ts) {
                GF_AEE_Meta::set_override($entry_id, $override_ts);

                // Reschedule pre-expiry notification for the new override date.
                wp_clear_scheduled_hook(GF_AEE_Scheduler::HOOK_PRE_NOTIFICATION, array((int) $entry_id));
                GF_AEE_Meta::set($entry_id, GF_AEE_Meta::NOTIFIED, 0);

                $feed_id = GF_AEE_Meta::get($entry_id, GF_AEE_Meta::FEED_ID);
                if ($feed_id) {
                    $feed = $this->get_feed($feed_id);
                    if ($feed) {
                        $meta = rgar($feed, 'meta');
                        if (rgar($meta, 'enable_pre_notification')) {
                            $pre_value = absint(rgar($meta, 'pre_notify_value', 0));
                            $pre_unit  = rgar($meta, 'pre_notify_unit', 'days');
                            if ($pre_value > 0) {
                                $notify_ts = GF_AEE_Processor::apply_offset($override_ts, '-', $pre_value, $pre_unit);
                                if ($notify_ts > time()) {
                                    GF_AEE_Scheduler::schedule_pre_notification($entry_id, $notify_ts);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Remove override.
        if (! empty($_POST['gf_aee_remove_override'])) {
            GF_AEE_Meta::remove_override($entry_id);
        }

        // Bust dashboard widget cache after any meta change.
        GF_AEE_Dashboard::invalidate_cache();
    }

    /* ─── Plugin Settings (Dry-run + Retroactive Tool) ────────────────── */

    public function plugin_settings_fields()
    {
        return array(
            array(
                'title'  => esc_html__('General Settings', 'gf-advanced-expiring-entries'),
                'fields' => array(
                    array(
                        'label'   => esc_html__('Dry-Run Mode', 'gf-advanced-expiring-entries'),
                        'type'    => 'checkbox',
                        'name'    => 'enable_dry_run',
                        'tooltip' => esc_html__('When enabled, the expiry runner logs what it would do but takes no real action.', 'gf-advanced-expiring-entries'),
                        'choices' => array(
                            array(
                                'label' => esc_html__('Enable dry-run mode', 'gf-advanced-expiring-entries'),
                                'name'  => 'enable_dry_run',
                            ),
                        ),
                    ),
                    array(
                        'label'         => esc_html__('Check Interval (minutes)', 'gf-advanced-expiring-entries'),
                        'type'          => 'text',
                        'name'          => 'check_interval',
                        'class'         => 'small',
                        'input_type'    => 'number',
                        'default_value' => '1',
                        'tooltip'       => esc_html__('How often the expiry check runs, in minutes. Default: 1.', 'gf-advanced-expiring-entries'),
                        'after_input'   => ' ' . esc_html__('min', 'gf-advanced-expiring-entries'),
                    ),
                ),
            ),
            array(
                'title'       => esc_html__('Recompute Expiry for Existing Entries', 'gf-advanced-expiring-entries'),
                'description' => esc_html__('Select a form, a feed, and a processing mode, then click "Run" to apply expiry rules to existing entries.', 'gf-advanced-expiring-entries'),
                'fields'      => array(
                    array(
                        'name' => 'retroactive_tool',
                        'type' => 'retroactive_tool',
                    ),
                ),
            ),
            array(
                'title'       => esc_html__('Manual Expiry Check', 'gf-advanced-expiring-entries'),
                'description' => esc_html__('Manually trigger the expiry check right now, without waiting for the scheduled interval.', 'gf-advanced-expiring-entries'),
                'fields'      => array(
                    array(
                        'name' => 'manual_expiry_check_button',
                        'type' => 'expiry_check_button',
                    ),
                ),
            ),
            array(
                'title'       => esc_html__('Expiry Log', 'gf-advanced-expiring-entries'),
                'description' => esc_html__('Recent expiry actions executed by the plugin.', 'gf-advanced-expiring-entries'),
                'fields'      => array(
                    array(
                        'name' => 'expiry_log_table',
                        'type' => 'expiry_log',
                    ),
                ),
            ),
        );
    }

    /**
     * Helper: build form choices for the plugin settings page.
     *
     * Only includes forms that have at least one active Expiring Entries feed.
     */
    private static function get_form_choices()
    {
        $choices = array(
            array('label' => esc_html__('— Select a form —', 'gf-advanced-expiring-entries'), 'value' => ''),
        );
        $addon = gf_aee();
        if (! $addon) {
            return $choices;
        }
        $forms = GFAPI::get_forms();
        foreach ($forms as $form) {
            $form_id = rgar($form, 'id');
            $feeds   = $addon->get_feeds($form_id);
            // Only show forms that have at least one active AEE feed.
            $has_active = false;
            if (is_array($feeds)) {
                foreach ($feeds as $feed) {
                    if (rgar($feed, 'is_active')) {
                        $has_active = true;
                        break;
                    }
                }
            }
            if ($has_active) {
                $choices[] = array('label' => rgar($form, 'title'), 'value' => $form_id);
            }
        }
        return $choices;
    }

    /* ─── Custom settings field renderers ──────────────────────────────── */

    /**
     * Render the retroactive tool: form + feed selects inline, then button.
     */
    public function settings_retroactive_tool($field, $echo = true)
    {
        $form_choices = self::get_form_choices();
        ob_start();
?>
        <div class="gf-aee-retroactive-selects">
            <select name="_gform_setting_retroactive_form_id" class="gform-input__select">
                <?php foreach ($form_choices as $choice) : ?>
                    <option value="<?php echo esc_attr($choice['value']); ?>"><?php echo esc_html($choice['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="gf_aee_retroactive_feed_select" name="_gform_setting_retroactive_feed_id" class="gform-input__select">
                <option value=""><?php esc_html_e('— Select a form first —', 'gf-advanced-expiring-entries'); ?></option>
            </select>
            <select id="gf_aee_retroactive_mode" name="_gform_setting_retroactive_mode" class="gform-input__select">
                <option value="missing"><?php esc_html_e('Only entries without expiry timestamp', 'gf-advanced-expiring-entries'); ?></option>
                <option value="all"><?php esc_html_e('All entries (recompute all)', 'gf-advanced-expiring-entries'); ?></option>
            </select>
        </div>
        <div class="gf-aee-tool-action">
            <button type="button" class="button" id="gf_aee_run_retroactive">
                <?php esc_html_e('Run Retroactive Processing', 'gf-advanced-expiring-entries'); ?>
            </button>
            <span id="gf_aee_retroactive_spinner" class="spinner" style="float:none;"></span>
            <span id="gf_aee_retroactive_status" class="gf-aee-action-msg"></span>
        </div>
        <?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render the manual expiry check button (custom field type).
     */
    public function settings_expiry_check_button($field, $echo = true)
    {
        ob_start();
        ?>
        <div class="gf-aee-tool-action">
            <button type="button" class="button" id="gf_aee_run_expiry_check">
                <?php esc_html_e('Run Expiry Check Now', 'gf-advanced-expiring-entries'); ?>
            </button>
            <span id="gf_aee_expiry_check_spinner" class="spinner" style="float:none;"></span>
            <span id="gf_aee_expiry_check_status" class="gf-aee-action-msg"></span>
        </div>
<?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render the expiry log table (custom field type).
     */
    public function settings_expiry_log($field, $echo = true)
    {
        $base_url = admin_url('admin.php?page=gf_settings&subview=' . $this->_slug);
        ob_start();
        GF_AEE_Log::render_page($base_url);
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render the live feed summary (custom field type for feed settings).
     */
    public function settings_feed_summary($field, $echo = true)
    {
        $html = '<div id="gf-aee-feed-summary" class="gf-aee-feed-summary">'
            . '<em>' . esc_html__('Adjust the settings above to see a summary of this feed rule.', 'gf-advanced-expiring-entries') . '</em>'
            . '</div>';
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render a combined number + unit select on one line (custom field type).
     */
    public function settings_notify_delay($field, $echo = true)
    {
        $value_name = rgar($field, 'name');
        $unit_name  = rgar($field, 'unit_name', $value_name . '_unit');

        $current_value = $this->get_setting($value_name, '');
        $current_unit  = $this->get_setting($unit_name, 'days');

        $units = array(
            'minutes' => esc_html__('Minutes', 'gf-advanced-expiring-entries'),
            'hours'   => esc_html__('Hours',   'gf-advanced-expiring-entries'),
            'days'    => esc_html__('Days',    'gf-advanced-expiring-entries'),
            'weeks'   => esc_html__('Weeks',   'gf-advanced-expiring-entries'),
        );

        ob_start();
        ?>
        <div class="gf-aee-notify-delay">
            <input type="number"
                   name="_gform_setting_<?php echo esc_attr($value_name); ?>"
                   value="<?php echo esc_attr($current_value); ?>"
                   class="gform-input__input small"
                   min="0" />
            <select name="_gform_setting_<?php echo esc_attr($unit_name); ?>"
                    class="gform-input__select">
                <?php foreach ($units as $val => $label) : ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($current_unit, $val); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render inline offset: direction + value + unit on one line (custom field type).
     */
    /**
     * Render the combined "Expire At" + "Offset" timing row.
     */
    public function settings_expiry_timing($field, $echo = true)
    {
        $current_time  = $this->get_setting('expiry_time', '');
        $current_dir   = $this->get_setting('offset_direction', '+');
        $current_value = $this->get_setting('offset_value', '0');
        $current_unit  = $this->get_setting('offset_unit', 'minutes');

        $units = array(
            'minutes' => esc_html__('Minutes', 'gf-advanced-expiring-entries'),
            'hours'   => esc_html__('Hours',   'gf-advanced-expiring-entries'),
            'days'    => esc_html__('Days',    'gf-advanced-expiring-entries'),
            'weeks'   => esc_html__('Weeks',   'gf-advanced-expiring-entries'),
            'months'  => esc_html__('Months',  'gf-advanced-expiring-entries'),
        );

        ob_start();
        ?>
        <div class="gf-aee-timing-inline">
            <select name="_gform_setting_expiry_time" class="gform-input__select">
                <option value="" <?php selected($current_time, ''); ?>>— <?php esc_html_e('No specific time', 'gf-advanced-expiring-entries'); ?> —</option>
                <?php for ($h = 0; $h < 24; $h++) :
                    $t = sprintf('%02d:00', $h); ?>
                    <option value="<?php echo esc_attr($t); ?>" <?php selected($current_time, $t); ?>><?php echo esc_html($t); ?></option>
                <?php endfor; ?>
                <option value="23:59" <?php selected($current_time, '23:59'); ?>>23:59</option>
            </select>
            <select name="_gform_setting_offset_direction" class="gform-input__select">
                <option value="+" <?php selected($current_dir, '+'); ?>>+</option>
                <option value="-" <?php selected($current_dir, '-'); ?>>−</option>
            </select>
            <input type="number"
                   name="_gform_setting_offset_value"
                   value="<?php echo esc_attr($current_value); ?>"
                   class="gform-input__input small"
                   min="0" />
            <select name="_gform_setting_offset_unit" class="gform-input__select">
                <?php foreach ($units as $val => $label) : ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($current_unit, $val); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * Render a button-group field (toggle buttons for selecting a value).
     */
    public function settings_button_group($field, $echo = true)
    {
        $name    = rgar($field, 'name');
        $choices = rgar($field, 'choices', array());
        $current = $this->get_setting($name, rgar($field, 'default_value', ''));

        ob_start();
        ?>
        <div class="gf-aee-button-group">
            <?php foreach ($choices as $choice) :
                $value    = esc_attr($choice['value']);
                $label    = esc_html($choice['label']);
                $tooltip  = ! empty($choice['tooltip']) ? $choice['tooltip'] : '';
                $disabled = ! empty($choice['disabled']);
                $active   = ($current === $choice['value']) ? ' gf-aee-button-group__btn--active' : '';
                $dis_cls  = $disabled ? ' gf-aee-button-group__btn--disabled' : '';
            ?>
                <label class="gf-aee-button-group__btn<?php echo $active . $dis_cls; ?>"
                       <?php if ($tooltip) : ?>title="<?php echo esc_attr($tooltip); ?>"<?php endif; ?>>
                    <input type="radio"
                           name="_gform_setting_<?php echo esc_attr($name); ?>"
                           value="<?php echo $value; ?>"
                           <?php checked($current, $choice['value']); ?>
                           <?php if ($disabled) : ?>disabled="disabled"<?php endif; ?>
                           style="display:none;" />
                    <?php echo $label; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /* ─── Dry-run notice ──────────────────────────────────────────────── */

    public function maybe_show_dry_run_notice()
    {
        $settings = $this->get_plugin_settings();
        if (rgar($settings, 'enable_dry_run') && GFForms::is_gravity_page()) {
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html__('GF Advanced Expiring Entries: Dry-Run mode is ACTIVE.', 'gf-advanced-expiring-entries')
                . '</strong> '
                . esc_html__('No expiry actions will be executed. Disable this in the plugin settings.', 'gf-advanced-expiring-entries')
                . '</p></div>';
        }

        // Warn if GF's built-in auto-deletion is also active.
        if (GFForms::is_gravity_page() && (has_filter('gform_trash_expired_entries') || has_filter('gform_delete_expired_entries'))) {
            echo '<div class="notice notice-info"><p><strong>'
                . esc_html__('GF Advanced Expiring Entries:', 'gf-advanced-expiring-entries')
                . '</strong> '
                . esc_html__('Gravity Forms\' own auto-deletion (gform_trash_expired_entries / gform_delete_expired_entries) is also active. Entries may be trashed or deleted by GF before this plugin\'s rules run. Consider disabling one to avoid conflicts.', 'gf-advanced-expiring-entries')
                . '</p></div>';
        }
    }

    /* ─── AJAX: Retroactive Processing ────────────────────────────────── */

    public function init_ajax()
    {
        parent::init_ajax();

        add_action('wp_ajax_gf_aee_run_retroactive', array($this, 'ajax_run_retroactive'));
        add_action('wp_ajax_gf_aee_run_expiry_check', array($this, 'ajax_run_expiry_check'));
        add_action('wp_ajax_gf_aee_feed_summary', array($this, 'ajax_feed_summary'));
        add_action('wp_ajax_gf_aee_get_feeds_for_form', array($this, 'ajax_get_feeds_for_form'));
        add_action('wp_ajax_gf_aee_filter_log', array($this, 'ajax_filter_log'));
    }

    /**
     * AJAX handler: process existing entries retroactively.
     */
    public function ajax_run_retroactive()
    {
        check_ajax_referer('gf_aee_retroactive', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'gf-advanced-expiring-entries'));
        }

        $form_id = absint(rgpost('form_id'));
        $feed_id = absint(rgpost('feed_id'));
        $mode    = sanitize_key(rgpost('mode'));
        if (! in_array($mode, array('missing', 'all'), true)) {
            $mode = 'missing';
        }

        if (! $form_id || ! $feed_id) {
            wp_send_json_error(__('Missing form_id or feed_id.', 'gf-advanced-expiring-entries'));
        }

        $feed = $this->get_feed($feed_id);
        if (! $feed) {
            wp_send_json_error(__('Feed not found.', 'gf-advanced-expiring-entries'));
        }

        $form = GFAPI::get_form($form_id);
        if (! $form) {
            wp_send_json_error(__('Form not found.', 'gf-advanced-expiring-entries'));
        }

        $search_criteria = array('status' => 'active');
        $paging          = array('offset' => 0, 'page_size' => 50);
        $processed       = 0;

        do {
            $entries = GFAPI::get_entries($form_id, $search_criteria, null, $paging);

            if (empty($entries)) {
                break;
            }

            foreach ($entries as $entry) {
                if ($mode === 'missing') {
                    $existing = GF_AEE_Meta::get($entry['id'], GF_AEE_Meta::EXPIRY_TS);
                    if (! empty($existing)) {
                        continue;
                    }
                }
                GF_AEE_Processor::process($feed, $entry, $form);
                $processed++;
            }

            $paging['offset'] += $paging['page_size'];
        } while (count($entries) === $paging['page_size']);

        wp_send_json_success(array(
            'processed' => $processed,
            'message'   => sprintf(
                /* translators: %d = number of entries */
                __('Processed %d entries.', 'gf-advanced-expiring-entries'),
                $processed
            ),
        ));
    }

    /**
     * AJAX handler: return feeds for a given form.
     */
    public function ajax_get_feeds_for_form()
    {
        check_ajax_referer('gf_aee_get_feeds', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'gf-advanced-expiring-entries'));
        }

        $form_id = absint(rgpost('form_id'));
        if (! $form_id) {
            wp_send_json_error(__('Missing form_id.', 'gf-advanced-expiring-entries'));
        }

        $feeds  = $this->get_feeds($form_id);
        $result = array();

        if (is_array($feeds)) {
            foreach ($feeds as $feed) {
                $result[] = array(
                    'id'   => rgar($feed, 'id'),
                    'name' => rgars($feed, 'meta/feed_name') ?: sprintf(__('Feed #%d', 'gf-advanced-expiring-entries'), rgar($feed, 'id')),
                );
            }
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: filter the expiry log table.
     */
    public function ajax_filter_log()
    {
        check_ajax_referer('gf_aee_filter_log', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'gf-advanced-expiring-entries'));
        }

        $filters = array(
            'form_id' => absint(rgpost('form_id')),
            'action'  => sanitize_key(rgpost('action_filter')),
            'success' => sanitize_key(rgpost('success')),
        );
        if (empty($filters['success'])) {
            $filters['success'] = 'all';
        }
        $page_num = max(1, absint(rgpost('paged')));

        ob_start();
        GF_AEE_Log::render_results($filters, $page_num);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler: run expiry check immediately.
     */
    public function ajax_run_expiry_check()
    {
        check_ajax_referer('gf_aee_expiry_check', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'gf-advanced-expiring-entries'));
        }

        GF_AEE_Scheduler::run_expiry_check();

        wp_send_json_success(array(
            'message' => __('Expiry check completed.', 'gf-advanced-expiring-entries'),
        ));
    }

    /**
     * AJAX handler: build a human-readable summary of the current feed settings.
     */
    public function ajax_feed_summary()
    {
        check_ajax_referer('gf_aee_feed_summary', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'gf-advanced-expiring-entries'));
        }

        // Collect the feed meta values sent from JS.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
        $meta    = isset($_POST['meta']) && is_array($_POST['meta']) ? wp_unslash($_POST['meta']) : array();
        $form_id = absint(rgpost('form_id'));
        $form    = $form_id ? GFAPI::get_form($form_id) : null;

        $summary = self::build_feed_summary($meta, $form);

        wp_send_json_success(array('summary' => $summary));
    }

    /**
     * Build a human-readable description of a feed configuration.
     *
     * @param array      $meta Feed meta values.
     * @param array|null $form The form object (for field/notification labels).
     * @return string Plain-text summary.
     */
    public static function build_feed_summary($meta, $form = null)
    {
        $parts = array();

        // ── 1. Timing ──────────────────────────────────────────────────
        $expiry_type = sanitize_key(rgar($meta, 'expiry_type', 'entry_meta'));
        $offset_val  = absint(rgar($meta, 'offset_value', 0));
        $offset_unit = sanitize_key(rgar($meta, 'offset_unit', 'minutes'));
        $offset_dir  = rgar($meta, 'offset_direction', '+');

        $unit_labels = array(
            'minutes' => _n_noop('%d minute', '%d minutes', 'gf-advanced-expiring-entries'),
            'hours'   => _n_noop('%d hour', '%d hours', 'gf-advanced-expiring-entries'),
            'days'    => _n_noop('%d day', '%d days', 'gf-advanced-expiring-entries'),
            'weeks'   => _n_noop('%d week', '%d weeks', 'gf-advanced-expiring-entries'),
            'months'  => _n_noop('%d month', '%d months', 'gf-advanced-expiring-entries'),
        );

        // Pre-compute offset label.
        $unit_str  = '';
        $dir_label = '';
        if ($offset_val > 0) {
            $unit_noop = isset($unit_labels[$offset_unit]) ? $unit_labels[$offset_unit] : $unit_labels['minutes'];
            $unit_str  = sprintf(translate_nooped_plural($unit_noop, $offset_val, 'gf-advanced-expiring-entries'), $offset_val);
            $dir_label = $offset_dir === '-'
                ? __('before', 'gf-advanced-expiring-entries')
                : __('after', 'gf-advanced-expiring-entries');
        }

        // Resolve "Expire At" time (new `expiry_time` or legacy `snap_to`).
        $expiry_time = sanitize_text_field(rgar($meta, 'expiry_time', ''));
        if (empty($expiry_time)) {
            $snap = rgar($meta, 'snap_to', '');
            if ($snap === 'start') {
                $expiry_time = '00:00';
            } elseif ($snap === 'end') {
                $expiry_time = '23:59';
            }
        }

        if ($expiry_type === 'fixed') {
            $fixed_date = sanitize_text_field(rgar($meta, 'fixed_expiry_date', ''));
            if ($fixed_date) {
                $parts[] = sprintf(
                    /* translators: %s = fixed date string */
                    __('On %s,', 'gf-advanced-expiring-entries'),
                    $fixed_date
                );
            } else {
                $parts[] = __('At a fixed date (not yet set),', 'gf-advanced-expiring-entries');
            }
        } elseif ($expiry_type === 'entry_meta') {
            $source = sanitize_key(rgar($meta, 'entry_meta_source', 'date_created'));
            $source_label = $source === 'date_updated'
                ? __('entry last updated date', 'gf-advanced-expiring-entries')
                : __('entry creation date', 'gf-advanced-expiring-entries');

            if ($offset_val > 0 && $expiry_time) {
                $parts[] = sprintf(
                    /* translators: 1: offset string, 2: "before"/"after", 3: time (e.g. "10:00"), 4: source label */
                    __('%1$s %2$s %3$s on the %4$s,', 'gf-advanced-expiring-entries'),
                    ucfirst($unit_str),
                    $dir_label,
                    $expiry_time,
                    $source_label
                );
            } elseif ($expiry_time) {
                $parts[] = sprintf(
                    /* translators: 1: time (e.g. "10:00"), 2: source label */
                    __('At %1$s on the %2$s,', 'gf-advanced-expiring-entries'),
                    $expiry_time,
                    $source_label
                );
            } elseif ($offset_val > 0) {
                $parts[] = sprintf(
                    /* translators: 1: offset string (e.g. "1 minute"), 2: "before"/"after", 3: source label */
                    __('%1$s %2$s the %3$s,', 'gf-advanced-expiring-entries'),
                    ucfirst($unit_str),
                    $dir_label,
                    $source_label
                );
            } else {
                $parts[] = sprintf(
                    /* translators: %s = source label */
                    __('When the %s is reached,', 'gf-advanced-expiring-entries'),
                    $source_label
                );
            }
        } elseif ($expiry_type === 'dynamic') {
            $field_id    = rgar($meta, 'date_field_id', '');
            $field_label = $field_id && $form ? self::get_field_label($form, $field_id) : __('date field', 'gf-advanced-expiring-entries');

            if ($offset_val > 0 && $expiry_time) {
                $parts[] = sprintf(
                    /* translators: 1: offset string, 2: "before"/"after", 3: time, 4: field label */
                    __('%1$s %2$s %3$s on the "%4$s" field value,', 'gf-advanced-expiring-entries'),
                    ucfirst($unit_str),
                    $dir_label,
                    $expiry_time,
                    $field_label
                );
            } elseif ($expiry_time) {
                $parts[] = sprintf(
                    /* translators: 1: time, 2: field label */
                    __('At %1$s on the "%2$s" date,', 'gf-advanced-expiring-entries'),
                    $expiry_time,
                    $field_label
                );
            } elseif ($offset_val > 0) {
                $parts[] = sprintf(
                    /* translators: 1: offset string, 2: "before"/"after", 3: field label */
                    __('%1$s %2$s the "%3$s" field value,', 'gf-advanced-expiring-entries'),
                    ucfirst($unit_str),
                    $dir_label,
                    $field_label
                );
            } else {
                $parts[] = sprintf(
                    /* translators: %s = field label */
                    __('When the "%s" date is reached,', 'gf-advanced-expiring-entries'),
                    $field_label
                );
            }
        }

        // ── 2. Action ──────────────────────────────────────────────────
        $action = sanitize_key(rgar($meta, 'expiry_action', 'trash'));
        $action_labels = array(
            'trash'         => __('the entry is automatically moved to Trash', 'gf-advanced-expiring-entries'),
            'delete'        => __('the entry is permanently deleted', 'gf-advanced-expiring-entries'),
            'change_status' => '',
            'update_field'  => '',
            'webhook'       => __('a webhook is fired', 'gf-advanced-expiring-entries'),
            'notification'  => '',
        );

        if ($action === 'change_status') {
            $target = sanitize_key(rgar($meta, 'target_status', 'read'));
            $status_labels = array(
                'read'    => __('marked as read', 'gf-advanced-expiring-entries'),
                'unread'  => __('marked as unread', 'gf-advanced-expiring-entries'),
                'starred' => __('starred', 'gf-advanced-expiring-entries'),
            );
            $parts[] = sprintf(
                /* translators: %s = status label */
                __('the entry is %s', 'gf-advanced-expiring-entries'),
                isset($status_labels[$target]) ? $status_labels[$target] : $target
            );
        } elseif ($action === 'update_field') {
            $field_id    = rgar($meta, 'target_field_id', '');
            $field_label = $field_id && $form ? self::get_field_label($form, $field_id) : __('a field', 'gf-advanced-expiring-entries');
            $field_value = rgar($meta, 'target_field_value', '');
            $parts[] = sprintf(
                /* translators: 1: field label, 2: new value */
                __('the "%1$s" field is set to "%2$s"', 'gf-advanced-expiring-entries'),
                $field_label,
                $field_value
            );
        } elseif ($action === 'notification') {
            $notif_id    = rgar($meta, 'notification_id', '');
            $notif_label = '';
            if ($notif_id && $form && ! empty($form['notifications'][$notif_id])) {
                $notif_label = rgar($form['notifications'][$notif_id], 'name');
            }
            if ($notif_label) {
                $parts[] = sprintf(
                    /* translators: %s = notification name */
                    __('the "%s" notification is triggered', 'gf-advanced-expiring-entries'),
                    $notif_label
                );
            } else {
                $parts[] = __('a notification is triggered', 'gf-advanced-expiring-entries');
            }
        } else {
            $parts[] = isset($action_labels[$action]) ? $action_labels[$action] : $action;
        }

        // ── 3. Conditional logic ───────────────────────────────────────
        $condition_enabled = ! empty($meta['feed_condition_conditional_logic'])
            || ! empty($meta['feed_condition_conditional_logic_object']);

        $logic_obj = null;
        if (! empty($meta['feed_condition_conditional_logic_object'])) {
            $raw = $meta['feed_condition_conditional_logic_object'];
            if (is_string($raw)) {
                $logic_obj = json_decode($raw, true);
            } elseif (is_array($raw)) {
                $logic_obj = $raw;
            }
        }

        if ($condition_enabled && $logic_obj && ! empty($logic_obj['rules'])) {
            $logic_type = rgar($logic_obj, 'logicType', 'all') === 'any'
                ? __('any', 'gf-advanced-expiring-entries')
                : __('all', 'gf-advanced-expiring-entries');

            $rule_parts = array();
            foreach ($logic_obj['rules'] as $rule) {
                $rule_field_id = rgar($rule, 'fieldId', '');
                $rule_label    = $rule_field_id && $form ? self::get_field_label($form, $rule_field_id) : '#' . $rule_field_id;
                $rule_op       = rgar($rule, 'operator', 'is');
                $rule_value    = rgar($rule, 'value', '');

                $op_labels = array(
                    'is'              => __('is', 'gf-advanced-expiring-entries'),
                    'isnot'           => __('is not', 'gf-advanced-expiring-entries'),
                    'greater_than'    => __('is greater than', 'gf-advanced-expiring-entries'),
                    'less_than'       => __('is less than', 'gf-advanced-expiring-entries'),
                    'contains'        => __('contains', 'gf-advanced-expiring-entries'),
                    'starts_with'     => __('starts with', 'gf-advanced-expiring-entries'),
                    'ends_with'       => __('ends with', 'gf-advanced-expiring-entries'),
                );
                $op_str = isset($op_labels[$rule_op]) ? $op_labels[$rule_op] : $rule_op;

                $rule_parts[] = sprintf('"%s" %s "%s"', $rule_label, $op_str, $rule_value);
            }

            $parts[] = sprintf(
                /* translators: 1: "all"/"any", 2: comma-separated conditions */
                __('— but only if %1$s of the following match: %2$s', 'gf-advanced-expiring-entries'),
                $logic_type,
                implode(
                    /* translators: separator between conditions */
                    __(', ', 'gf-advanced-expiring-entries'),
                    $rule_parts
                )
            );
        }

        // ── 4. Pre-expiry notification ─────────────────────────────────
        // Add a period to close the action/condition sentence before
        // appending the pre-notification as a new sentence.
        if (! empty($parts)) {
            $last_key = array_key_last($parts);
            $parts[$last_key] = rtrim($parts[$last_key], ' ,;') . '.';
        }

        $pre_notify = ! empty($meta['enable_pre_notification']);
        if ($pre_notify) {
            $notify_value = absint(rgar($meta, 'pre_notify_value', 0));
            $notify_unit  = sanitize_key(rgar($meta, 'pre_notify_unit', 'hours'));
            $notify_notif = rgar($meta, 'pre_notify_notification_id', '');
            $notify_label = '';

            if ($notify_notif && $form && ! empty($form['notifications'][$notify_notif])) {
                $notify_label = rgar($form['notifications'][$notify_notif], 'name');
            }

            if ($notify_value > 0 && $notify_label) {
                $unit_noop = isset($unit_labels[$notify_unit]) ? $unit_labels[$notify_unit] : $unit_labels['hours'];
                $unit_str  = sprintf(translate_nooped_plural($unit_noop, $notify_value, 'gf-advanced-expiring-entries'), $notify_value);
                $parts[] = sprintf(
                    /* translators: 1: notification name, 2: time offset (e.g. "1 hour"), 3: always "before" */
                    __('"%1$s" notification is sent %2$s before the entry expires.', 'gf-advanced-expiring-entries'),
                    $notify_label,
                    $unit_str
                );
            } elseif ($notify_value > 0) {
                $unit_noop = isset($unit_labels[$notify_unit]) ? $unit_labels[$notify_unit] : $unit_labels['hours'];
                $unit_str  = sprintf(translate_nooped_plural($unit_noop, $notify_value, 'gf-advanced-expiring-entries'), $notify_value);
                $parts[] = sprintf(
                    /* translators: %s = time offset */
                    __('A pre-expiry notification is sent %s before the entry expires.', 'gf-advanced-expiring-entries'),
                    $unit_str
                );
            }
        }

        // ── 5. Post-expiry notifications ───────────────────────────────
        $post_types = array(
            'success' => __('successful', 'gf-advanced-expiring-entries'),
            'fail'    => __('failed', 'gf-advanced-expiring-entries'),
        );

        foreach ($post_types as $post_type => $type_label) {
            $post_enabled = ! empty($meta['enable_post_notification_' . $post_type]);
            if (! $post_enabled) {
                continue;
            }

            $post_value = absint(rgar($meta, 'post_notify_' . $post_type . '_value', 0));
            $post_unit  = sanitize_key(rgar($meta, 'post_notify_' . $post_type . '_unit', 'minutes'));
            $post_notif = rgar($meta, 'post_notify_' . $post_type . '_notification_id', '');
            $post_label = '';

            if ($post_notif && $form && ! empty($form['notifications'][$post_notif])) {
                $post_label = rgar($form['notifications'][$post_notif], 'name');
            }

            if ($post_value > 0 && $post_label) {
                $unit_noop = isset($unit_labels[$post_unit]) ? $unit_labels[$post_unit] : $unit_labels['hours'];
                $unit_str  = sprintf(translate_nooped_plural($unit_noop, $post_value, 'gf-advanced-expiring-entries'), $post_value);
                $parts[] = sprintf(
                    /* translators: 1: notification name, 2: time offset, 3: "successful"/"failed" */
                    __('"%1$s" notification is sent %2$s after a %3$s expiry action.', 'gf-advanced-expiring-entries'),
                    $post_label,
                    $unit_str,
                    $type_label
                );
            } elseif ($post_value > 0) {
                $unit_noop = isset($unit_labels[$post_unit]) ? $unit_labels[$post_unit] : $unit_labels['hours'];
                $unit_str  = sprintf(translate_nooped_plural($unit_noop, $post_value, 'gf-advanced-expiring-entries'), $post_value);
                $parts[] = sprintf(
                    /* translators: 1: time offset, 2: "successful"/"failed" */
                    __('A post-expiry notification is sent %1$s after a %2$s expiry action.', 'gf-advanced-expiring-entries'),
                    $unit_str,
                    $type_label
                );
            } elseif ($post_label) {
                $parts[] = sprintf(
                    /* translators: 1: notification name, 2: "successful"/"failed" */
                    __('"%1$s" notification is sent immediately after a %2$s expiry action.', 'gf-advanced-expiring-entries'),
                    $post_label,
                    $type_label
                );
            }
        }

        // Assemble with proper sentence joining.
        return implode(' ', $parts);
    }

    /**
     * Get a form field's label by ID.
     */
    private static function get_field_label($form, $field_id)
    {
        if (empty($form['fields'])) {
            return '#' . $field_id;
        }
        foreach ($form['fields'] as $field) {
            if ((string) $field->id === (string) $field_id) {
                return $field->label;
            }
        }
        return '#' . $field_id;
    }

    /* ─── Scripts & Styles ────────────────────────────────────────────── */

    /**
     * Localize the settings script with AJAX data on both the plugin settings
     * and feed settings pages.
     */
    public function maybe_localize_settings_script()
    {
        $is_plugin_settings = $this->is_plugin_settings($this->_slug);
        $is_feed_settings   = $this->is_form_settings($this->_slug);

        if (! $is_plugin_settings && ! $is_feed_settings) {
            return;
        }

        $data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        );

        // Plugin settings page data.
        if ($is_plugin_settings) {
            $data['nonce']              = wp_create_nonce('gf_aee_retroactive');
            $data['nonceExpiryCheck']   = wp_create_nonce('gf_aee_expiry_check');
            $data['nonceGetFeeds']      = wp_create_nonce('gf_aee_get_feeds');
            $data['nonceFilterLog']     = wp_create_nonce('gf_aee_filter_log');
            $data['selectFormFeed']     = __('Please select a form, a feed, and a processing mode.', 'gf-advanced-expiring-entries');
            $data['selectFormFirst']    = __('— Select a form first —', 'gf-advanced-expiring-entries');
            $data['loadingFeeds']       = __('Loading…', 'gf-advanced-expiring-entries');
            $data['noFeeds']            = __('— No feeds found —', 'gf-advanced-expiring-entries');
            $data['processing']         = __('Processing…', 'gf-advanced-expiring-entries');
            $data['errorPrefix']        = __('Error: ', 'gf-advanced-expiring-entries');
            $data['requestFailed']      = __('Request failed.', 'gf-advanced-expiring-entries');
            $data['runningExpiryCheck'] = __('Running…', 'gf-advanced-expiring-entries');
        }

        // Feed settings page data.
        if ($is_feed_settings) {
            $data['nonceFeedSummary'] = wp_create_nonce('gf_aee_feed_summary');
            $form = $this->get_current_form();
            $data['formId']           = $form ? rgar($form, 'id') : 0;
        }

        wp_localize_script('gf-aee-feed-settings', 'gf_aee_feed_settings_strings', $data);
    }

    public function scripts()
    {
        $scripts = array(
            array(
                'handle'  => 'gf-aee-feed-settings',
                'src'     => $this->get_base_url() . '/admin/assets/feed-settings.js',
                'version' => $this->_version,
                'deps'    => array('jquery', 'jquery-ui-datepicker'),
                'enqueue' => array(
                    array('admin_page' => array('form_settings'), 'tab' => $this->_slug),
                    array('admin_page' => array('plugin_settings'), 'tab' => $this->_slug),
                ),
            ),
        );
        return array_merge(parent::scripts(), $scripts);
    }

    public function styles()
    {
        $styles = array(
            array(
                'handle'  => 'gf-aee-feed-settings',
                'src'     => $this->get_base_url() . '/admin/assets/feed-settings.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array('admin_page' => array('form_settings'), 'tab' => $this->_slug),
                    array('admin_page' => array('plugin_settings', 'entry_list', 'entry_detail')),
                ),
            ),
        );
        return array_merge(parent::styles(), $styles);
    }

    /* ─── Menu icon ───────────────────────────────────────────────────── */

    public function get_menu_icon()
    {
        return 'dashicons-clock';
    }

    /* ─── Minimum requirements ────────────────────────────────────────── */

    public function minimum_requirements()
    {
        return array(
            'gravityforms' => array('version' => '2.7'),
            'wordpress'    => array('version' => '6.0'),
        );
    }
}
