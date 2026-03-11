<?php

/**
 * GF_AEE_Feed_Settings — Feed settings field definitions.
 *
 * Returns the GF settings-field array consumed by GF_AEE_Addon::feed_settings_fields().
 */

defined('ABSPATH') || exit;

class GF_AEE_Feed_Settings
{

    /**
     * Build and return all feed settings sections.
     *
     * @param  GF_AEE_Addon $addon The add-on instance (for helper calls).
     * @return array
     */
    public static function get_fields($addon)
    {

        $form = $addon->get_current_form();

        return array(
            self::section_feed_name(),
            self::section_expiry_source($form),
            self::section_expiry_action($form),
            self::section_notifications($form),
            self::section_empty_date_fallback(),
            self::section_conditional_logic(),
        );
    }

    /* ─── Section: Feed Name ──────────────────────────────────────────── */

    private static function section_feed_name()
    {
        return array(
            'title'  => esc_html__('Feed Name', 'gf-advanced-expiring-entries'),
            'fields' => array(
                array(
                    'label'    => esc_html__('Name', 'gf-advanced-expiring-entries'),
                    'type'     => 'text',
                    'name'     => 'feed_name',
                    'class'    => 'medium',
                    'required' => true,
                    'tooltip'  => esc_html__('Enter a name for this expiration feed.', 'gf-advanced-expiring-entries'),
                ),
                array(
                    'name' => 'feed_summary_display',
                    'type' => 'feed_summary',
                ),
            ),
        );
    }

    /* ─── Section: Expiry Source ───────────────────────────────────────── */

    private static function section_expiry_source($form)
    {

        // Collect date-type fields from the form.
        $date_fields    = self::get_date_field_choices($form);
        $has_date_fields = count($date_fields) > 1; // first choice is the placeholder

        return array(
            'title'  => esc_html__('Expiry Source', 'gf-advanced-expiring-entries'),
            'fields' => array(
                array(
                    'label'   => esc_html__('Expiry Type', 'gf-advanced-expiring-entries'),
                    'type'    => 'button_group',
                    'name'    => 'expiry_type',
                    'choices' => array(
                        array(
                            'label'   => esc_html__('Entry Date', 'gf-advanced-expiring-entries'),
                            'value'   => 'entry_meta',
                            'tooltip' => esc_attr__('Expire entries based on their creation or last-updated date.', 'gf-advanced-expiring-entries'),
                        ),
                        array(
                            'label'    => esc_html__('Date Field', 'gf-advanced-expiring-entries'),
                            'value'    => 'dynamic',
                            'tooltip'  => $has_date_fields
                                ? esc_attr__('Expire entries based on a date field value in the form.', 'gf-advanced-expiring-entries')
                                : esc_attr__('No date field present in the form.', 'gf-advanced-expiring-entries'),
                            'disabled' => ! $has_date_fields,
                        ),
                        array(
                            'label'   => esc_html__('Fixed Date', 'gf-advanced-expiring-entries'),
                            'value'   => 'fixed',
                            'tooltip' => esc_attr__('Expire all matching entries on a specific date.', 'gf-advanced-expiring-entries'),
                        ),
                    ),
                    'default_value' => 'entry_meta',
                ),
                // Fixed date.
                array(
                    'label'      => esc_html__('Fixed Expiry Date', 'gf-advanced-expiring-entries'),
                    'type'       => 'text',
                    'name'       => 'fixed_expiry_date',
                    'class'      => 'medium gf-aee-datepicker',
                    'tooltip'    => esc_html__('Select a fixed date/time at which entries expire.', 'gf-advanced-expiring-entries'),
                ),
                // Dynamic – date field selector.
                array(
                    'label'         => esc_html__('Date Field', 'gf-advanced-expiring-entries'),
                    'type'          => 'select',
                    'name'          => 'date_field_id',
                    'choices'       => $date_fields,
                    'default_value' => count($date_fields) === 2 ? $date_fields[1]['value'] : '',
                ),
                // Entry meta – source selector.
                array(
                    'label'      => esc_html__('Entry Date Source', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'entry_meta_source',
                    'choices'    => array(
                        array('label' => esc_html__('Entry creation date', 'gf-advanced-expiring-entries'), 'value' => 'date_created'),
                        array('label' => esc_html__('Entry last updated', 'gf-advanced-expiring-entries'),  'value' => 'date_updated'),
                    ),
                    'default_value' => 'date_created',
                ),
                // Expire At + Offset — combined on a single line.
                array(
                    'label'      => esc_html__('Timing', 'gf-advanced-expiring-entries'),
                    'type'       => 'expiry_timing',
                    'name'       => 'expiry_time',
                ),
            ),
        );
    }

    /* ─── Section: Expiry Action ──────────────────────────────────────── */

    private static function section_expiry_action($form)
    {

        $notifications = self::get_notification_choices($form);
        $fields        = self::get_all_field_choices($form);

        /**
         * Filter the list of available expiry actions.
         */
        $action_choices = apply_filters('gf_aee_expiry_actions', array(
            array('label' => esc_html__('Move to Trash', 'gf-advanced-expiring-entries'),       'value' => 'trash'),
            array('label' => esc_html__('Permanently Delete', 'gf-advanced-expiring-entries'),  'value' => 'delete'),
            array('label' => esc_html__('Change entry status', 'gf-advanced-expiring-entries'), 'value' => 'change_status'),
            array('label' => esc_html__('Update a field value', 'gf-advanced-expiring-entries'), 'value' => 'update_field'),
            array('label' => esc_html__('Fire a webhook', 'gf-advanced-expiring-entries'),      'value' => 'webhook'),
            array('label' => esc_html__('Trigger a GF notification', 'gf-advanced-expiring-entries'), 'value' => 'notification'),
        ));

        return array(
            'title'  => esc_html__('Expiry Action', 'gf-advanced-expiring-entries'),
            'fields' => array(
                array(
                    'label'   => esc_html__('Action', 'gf-advanced-expiring-entries'),
                    'type'    => 'select',
                    'name'    => 'expiry_action',
                    'choices' => $action_choices,
                ),
                // change_status → target status.
                array(
                    'label'      => esc_html__('Target Status', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'target_status',
                    'choices'    => array(
                        array('label' => esc_html__('Read', 'gf-advanced-expiring-entries'),    'value' => 'read'),
                        array('label' => esc_html__('Unread', 'gf-advanced-expiring-entries'),  'value' => 'unread'),
                        array('label' => esc_html__('Starred', 'gf-advanced-expiring-entries'), 'value' => 'starred'),
                    ),
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('change_status')),
                        ),
                    ),
                ),
                // update_field → field + value.
                array(
                    'label'      => esc_html__('Target Field', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'target_field_id',
                    'choices'    => $fields,
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('update_field')),
                        ),
                    ),
                ),
                array(
                    'label'      => esc_html__('New Field Value', 'gf-advanced-expiring-entries'),
                    'type'       => 'text',
                    'name'       => 'target_field_value',
                    'class'      => 'medium',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('update_field')),
                        ),
                    ),
                ),
                // webhook
                array(
                    'label'      => esc_html__('Webhook URL', 'gf-advanced-expiring-entries'),
                    'type'       => 'text',
                    'name'       => 'webhook_url',
                    'class'      => 'large',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('webhook')),
                        ),
                    ),
                ),
                array(
                    'label'      => esc_html__('Webhook Method', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'webhook_method',
                    'choices'    => array(
                        array('label' => 'POST', 'value' => 'POST'),
                        array('label' => 'GET',  'value' => 'GET'),
                    ),
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('webhook')),
                        ),
                    ),
                ),
                // notification
                array(
                    'label'      => esc_html__('Notification', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'notification_id',
                    'choices'    => $notifications,
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'expiry_action', 'values' => array('notification')),
                        ),
                    ),
                ),
            ),
        );
    }

    /* ─── Section: Notifications (unified) ────────────────────────────── */

    private static function section_notifications($form)
    {

        $notifications = self::get_notification_choices($form);

        return array(
            'title'  => esc_html__('Notifications', 'gf-advanced-expiring-entries'),
            'fields' => array(
                // ── Pre-expiry ──
                array(
                    'label'   => esc_html__('Pre-Expiry', 'gf-advanced-expiring-entries'),
                    'type'    => 'checkbox',
                    'name'    => 'enable_pre_notification',
                    'choices' => array(
                        array('label' => esc_html__('Send a notification before expiry', 'gf-advanced-expiring-entries'), 'name' => 'enable_pre_notification'),
                    ),
                ),
                array(
                    'label'      => esc_html__('Time Before Expiry', 'gf-advanced-expiring-entries'),
                    'type'       => 'notify_delay',
                    'name'       => 'pre_notify_value',
                    'unit_name'  => 'pre_notify_unit',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_pre_notification', 'values' => array('1')),
                        ),
                    ),
                ),
                array(
                    'label'      => esc_html__('Notification to Send', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'pre_notify_notification_id',
                    'choices'    => $notifications,
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_pre_notification', 'values' => array('1')),
                        ),
                    ),
                ),
                // ── Post-expiry (success) ──
                array(
                    'label'   => esc_html__('On Successful Expiry', 'gf-advanced-expiring-entries'),
                    'type'    => 'checkbox',
                    'name'    => 'enable_post_notification_success',
                    'choices' => array(
                        array('label' => esc_html__('Send a notification after a successful expiry action', 'gf-advanced-expiring-entries'), 'name' => 'enable_post_notification_success'),
                    ),
                ),
                array(
                    'label'      => esc_html__('Time After Expiry Action', 'gf-advanced-expiring-entries'),
                    'type'       => 'notify_delay',
                    'name'       => 'post_notify_success_value',
                    'unit_name'  => 'post_notify_success_unit',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_post_notification_success', 'values' => array('1')),
                        ),
                    ),
                ),
                array(
                    'label'      => esc_html__('Notification to Send', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'post_notify_success_notification_id',
                    'choices'    => $notifications,
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_post_notification_success', 'values' => array('1')),
                        ),
                    ),
                ),
                // ── Post-expiry (fail) ──
                array(
                    'label'   => esc_html__('On Failed Expiry', 'gf-advanced-expiring-entries'),
                    'type'    => 'checkbox',
                    'name'    => 'enable_post_notification_fail',
                    'choices' => array(
                        array('label' => esc_html__('Send a notification after a failed expiry action', 'gf-advanced-expiring-entries'), 'name' => 'enable_post_notification_fail'),
                    ),
                ),
                array(
                    'label'      => esc_html__('Time After Expiry Action', 'gf-advanced-expiring-entries'),
                    'type'       => 'notify_delay',
                    'name'       => 'post_notify_fail_value',
                    'unit_name'  => 'post_notify_fail_unit',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_post_notification_fail', 'values' => array('1')),
                        ),
                    ),
                ),
                array(
                    'label'      => esc_html__('Notification to Send', 'gf-advanced-expiring-entries'),
                    'type'       => 'select',
                    'name'       => 'post_notify_fail_notification_id',
                    'choices'    => $notifications,
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'enable_post_notification_fail', 'values' => array('1')),
                        ),
                    ),
                ),
            ),
        );
    }

    /* ─── Section: Missing Date Handling ───────────────────────────────── */

    private static function section_empty_date_fallback()
    {
        return array(
            'title'      => esc_html__('Missing Date Handling', 'gf-advanced-expiring-entries'),
            'dependency' => array(
                'live'   => true,
                'fields' => array(
                    array('field' => 'expiry_type', 'values' => array('dynamic')),
                ),
            ),
            'fields' => array(
                array(
                    'label'   => esc_html__('Fallback', 'gf-advanced-expiring-entries'),
                    'type'    => 'radio',
                    'name'    => 'empty_date_fallback',
                    'choices' => array(
                        array('label' => esc_html__('Do not set expiry (entry stays forever)', 'gf-advanced-expiring-entries'), 'value' => 'skip'),
                        array('label' => esc_html__('Use entry creation date', 'gf-advanced-expiring-entries'), 'value' => 'entry_date'),
                        array('label' => esc_html__('Use a specific fixed date', 'gf-advanced-expiring-entries'), 'value' => 'fixed_fallback'),
                    ),
                    'default_value' => 'skip',
                ),
                array(
                    'label'      => esc_html__('Fallback Fixed Date', 'gf-advanced-expiring-entries'),
                    'type'       => 'text',
                    'name'       => 'fallback_fixed_date',
                    'class'      => 'medium gf-aee-datepicker',
                    'dependency' => array(
                        'live'   => true,
                        'fields' => array(
                            array('field' => 'empty_date_fallback', 'values' => array('fixed_fallback')),
                        ),
                    ),
                ),
            ),
        );
    }

    /* ─── Section: Conditional Logic ──────────────────────────────────── */

    private static function section_conditional_logic()
    {
        return array(
            'title'  => esc_html__('Conditional Logic', 'gf-advanced-expiring-entries'),
            'fields' => array(
                array(
                    'name'           => 'condition',
                    'type'           => 'feed_condition',
                    'label'          => esc_html__('Conditional Logic', 'gf-advanced-expiring-entries'),
                    'checkbox_label' => esc_html__('Enable', 'gf-advanced-expiring-entries'),
                    'instructions'   => esc_html__('Process this feed if', 'gf-advanced-expiring-entries'),
                    'tooltip'        => esc_html__('When enabled, the expiry rule will only apply when the conditions are met.', 'gf-advanced-expiring-entries'),
                ),
            ),
        );
    }

	/* ─── Helpers ──────────────────────────────────────────────────────── */

    /**
     * Build choices array from all date-type fields in a form.
     */
    private static function get_date_field_choices($form)
    {
        $choices = array(
            array('label' => esc_html__('— Select a date field —', 'gf-advanced-expiring-entries'), 'value' => ''),
        );

        if (! empty($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if ($field->type === 'date') {
                    $choices[] = array(
                        'label' => $field->label,
                        'value' => (string) $field->id,
                    );
                }
            }
        }

        return $choices;
    }

    /**
     * Build choices array from all form notifications.
     */
    private static function get_notification_choices($form)
    {
        $choices = array(
            array('label' => esc_html__('— Select a notification —', 'gf-advanced-expiring-entries'), 'value' => ''),
        );

        if (! empty($form['notifications'])) {
            foreach ($form['notifications'] as $id => $notification) {
                $choices[] = array(
                    'label' => rgar($notification, 'name'),
                    'value' => $id,
                );
            }
        }

        return $choices;
    }

    /**
     * Build choices array from all fields in a form.
     */
    private static function get_all_field_choices($form)
    {
        $choices = array(
            array('label' => esc_html__('— Select a field —', 'gf-advanced-expiring-entries'), 'value' => ''),
        );

        if (! empty($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $choices[] = array(
                    'label' => $field->label,
                    'value' => (string) $field->id,
                );
            }
        }

        return $choices;
    }
}
