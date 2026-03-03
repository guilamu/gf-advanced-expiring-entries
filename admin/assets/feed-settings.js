/**
 * GF Advanced Expiring Entries — Feed settings JS.
 *
 * Handles datepicker initialisation, plugin-settings AJAX tools
 * (retroactive processing, manual expiry check), and live feed summary.
 */

(function ($) {
    'use strict';

    var strings = typeof gf_aee_feed_settings_strings !== 'undefined' ? gf_aee_feed_settings_strings : null;

    /* ── Datepicker ───────────────────────────────────────────────────── */
    function initDatepickers() {
        $('.gf-aee-datepicker').each(function () {
            if (!$(this).hasClass('hasDatepicker')) {
                $(this).datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true,
                });
            }
        });
    }

    /* ── Retroactive tool ─────────────────────────────────────────────── */
    function initRetroactive() {
        if (!strings) return;

        $('#gf_aee_run_retroactive').on('click', function (e) {
            e.preventDefault();

            var $btn     = $(this);
            var $spinner = $('#gf_aee_retroactive_spinner');
            var $status  = $('#gf_aee_retroactive_status');
            var formId   = $('[name="_gform_setting_retroactive_form_id"]').val();
            var feedId   = $('[name="_gform_setting_retroactive_feed_id"]').val();

            if (!formId || !feedId) {
                $status.text(strings.selectFormFeed);
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(strings.processing);

            $.post(strings.ajaxurl, {
                action:  'gf_aee_run_retroactive',
                nonce:   strings.nonce,
                form_id: formId,
                feed_id: feedId,
            }, function (response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (response.success) {
                    $status.text(response.data.message);
                } else {
                    $status.text(strings.errorPrefix + response.data);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $status.text(strings.requestFailed);
            });
        });
    }

    /* ── Manual expiry check ─────────────────────────────────────────── */
    function initExpiryCheck() {
        if (!strings) return;

        $('#gf_aee_run_expiry_check').on('click', function (e) {
            e.preventDefault();

            var $btn     = $(this);
            var $spinner = $('#gf_aee_expiry_check_spinner');
            var $status  = $('#gf_aee_expiry_check_status');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(strings.runningExpiryCheck);

            $.post(strings.ajaxurl, {
                action: 'gf_aee_run_expiry_check',
                nonce:  strings.nonceExpiryCheck,
            }, function (response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (response.success) {
                    $status.text(response.data.message);
                } else {
                    $status.text(strings.errorPrefix + response.data);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $status.text(strings.requestFailed);
            });
        });
    }

    /* ── Live feed summary ────────────────────────────────────────────── */
    var summaryTimer = null;

    /**
     * Collect all feed meta values from the form, including conditional logic.
     */
    function collectFeedMeta() {
        var meta = {};

        // Standard GF settings fields use name="_gform_setting_<name>".
        $('[name^="_gform_setting_"]').each(function () {
            var name = $(this).attr('name').replace('_gform_setting_', '');
            var $el  = $(this);

            // Skip unchecked radio/checkbox.
            if ($el.is(':radio') && !$el.is(':checked')) return;
            if ($el.is(':checkbox')) {
                // GF checkboxes: if checked the value is "1", otherwise skip.
                if (!$el.is(':checked')) return;
            }

            meta[name] = $el.val();
        });

        // GF conditional logic is stored in a hidden textarea.
        var $logicObj = $('[name="_gform_setting_feed_condition_conditional_logic_object"]');
        if ($logicObj.length) {
            try {
                meta.feed_condition_conditional_logic_object = JSON.parse($logicObj.val());
            } catch (e) {
                // ignore parse errors
            }
        }

        // Also check if conditional logic is enabled.
        var $logicCheck = $('[name="_gform_setting_feed_condition_conditional_logic"]');
        if ($logicCheck.length && $logicCheck.is(':checked')) {
            meta.feed_condition_conditional_logic = '1';
        }

        return meta;
    }

    function requestFeedSummary() {
        if (!strings || !strings.nonceFeedSummary) return;

        var $target = $('#gf-aee-feed-summary');
        if (!$target.length) return;

        var meta = collectFeedMeta();

        $.post(strings.ajaxurl, {
            action:  'gf_aee_feed_summary',
            nonce:   strings.nonceFeedSummary,
            form_id: strings.formId || 0,
            meta:    meta,
        }, function (response) {
            if (response.success && response.data.summary) {
                $target.html('<p>' + $('<span>').text(response.data.summary).html() + '</p>');
            }
        });
    }

    function scheduleSummaryUpdate() {
        if (summaryTimer) clearTimeout(summaryTimer);
        summaryTimer = setTimeout(requestFeedSummary, 400);
    }

    function initFeedSummary() {
        if (!strings || !strings.nonceFeedSummary) return;

        var $target = $('#gf-aee-feed-summary');
        if (!$target.length) return;

        // Listen for changes on all feed settings fields.
        $(document).on(
            'change input',
            '[name^="_gform_setting_"]',
            scheduleSummaryUpdate
        );

        // Also listen to GF's conditional logic changes (hidden textarea).
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.type === 'attributes' || m.type === 'childList') {
                    scheduleSummaryUpdate();
                }
            });
        });

        $('[name="_gform_setting_feed_condition_conditional_logic_object"]').each(function () {
            observer.observe(this, { attributes: true, attributeFilter: ['value'] });
        });

        // Initial load.
        requestFeedSummary();
    }

    /* ── Init ─────────────────────────────────────────────────────────── */
    $(document).ready(function () {
        initDatepickers();
        initRetroactive();
        initExpiryCheck();
        initFeedSummary();

        // Re-init datepickers after GF AJAX refreshes settings markup.
        $(document).on('gform_post_render', initDatepickers);
    });

})(jQuery);
