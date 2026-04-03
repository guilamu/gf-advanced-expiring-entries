# GF Advanced Expiring Entries

A Gravity Forms Feed Add-On that lets you create per-form expiration rules for entries. When an entry is submitted, the plugin computes an expiry timestamp and stores it as entry meta. A background job periodically checks for due entries and triggers the configured action. Pre-expiry and post-expiry notifications can be sent automatically.

![Plugin Screenshot](https://github.com/guilamu/gf-advanced-expiring-entries/blob/main/screenshot.png)

## Expiry Sources

- **Fixed date** — set a single calendar date shared by all entries on a form
- **Dynamic (date field)** — base expiry on any Date-type form field, with optional offset and day-snapping
- **Entry date** — expire relative to entry creation or last-updated date with configurable offset

## Expiry Actions

- **Move to Trash** — soft-delete the entry (recoverable from GF trash)
- **Permanently Delete** — removes the entry; a full backup is saved to a dedicated database table before deletion
- **Change Status** — mark as read, unread, or starred
- **Update a Field Value** — set any field to a new value on expiry
- **Fire a Webhook** — send entry data to an external URL (POST or GET)
- **Trigger a GF Notification** — fire any existing Gravity Forms notification
- **Anonymize** — wipe all field values from the entry while keeping the entry row itself, preserving submission counts; optionally clear IP/URL, user reference, and delete uploaded files

## Key Features

- **Pre-Expiry Notifications:** Send a GF notification minutes, hours, days, or weeks before an entry expires
- **Post-Expiry Notifications:** Send a GF notification after a successful or failed expiry action, with configurable delay
- **Per-Entry Overrides:** Admins can override, extend, or exempt individual entries from the entry detail sidebar
- **Dashboard Widget:** At-a-glance summary of active, expiring-soon, and expired entries per form
- **Dry-Run Mode:** Log every action without executing, for safe testing before going live
- **Retroactive Tool:** Apply expiry rules to existing entries that pre-date plugin installation
- **Conditional Logic:** Process feeds only when specific field conditions are met
- **Live Feed Summary:** Real-time human-readable description of the feed rule as you configure it
- **Expiry Log:** Full audit log of every action with live AJAX filtering by form, action type, and result
- **Self-Healing Cron:** Detects and recovers from stalled wp_cron events automatically
- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized; French translation included
- **Secure:** Nonce-verified AJAX, capability checks, prepared SQL queries throughout
- **GitHub Updates:** Automatic updates from GitHub releases via the WordPress admin

## Requirements

- WordPress 6.0 or higher
- PHP 8.1 or higher
- Gravity Forms 2.7 or higher

## Installation

1. Upload the `gf-advanced-expiring-entries` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to a form → **Settings → Expiring Entries** to create your first expiry feed
4. Adjust global settings (dry-run mode, check interval) under **Forms → Settings → Expiring Entries**

## FAQ

### Does this plugin require Gravity Forms?
Yes. Gravity Forms 2.7 or later is required. The plugin will not load without it.

### What happens if wp_cron is not running properly?
The plugin includes a self-healing mechanism that detects overdue cron events and runs the expiry check inline while rescheduling for the future. No manual intervention needed.

### Is permanently deleted entry data recoverable?
Yes. Before permanent deletion, the full entry data and action log are backed up to the `wp_gf_aee_deleted_entries` database table.

### Can I customize the available expiry actions?
Yes, use the `gf_aee_expiry_actions` filter:
```php
add_filter( 'gf_aee_expiry_actions', function( $actions ) {
    $actions[] = array(
        'label' => 'My Custom Action',
        'value' => 'my_custom',
    );
    return $actions;
} );
```
Then handle it with the `gf_aee_custom_expiry_action` filter.

### Can I modify the computed expiry timestamp?
Yes, use the `gf_aee_computed_expiry_ts` filter:
```php
add_filter( 'gf_aee_computed_expiry_ts', function( $expiry_ts, $entry, $feed, $form ) {
    // Add 1 hour to every computed expiry
    return $expiry_ts + HOUR_IN_SECONDS;
}, 10, 4 );
```

### How do I enable debug logging?
Define `GF_AEE_DEBUG` as `true` in your `wp-config.php`:
```php
define( 'GF_AEE_DEBUG', true );
```
Debug messages will appear in `wp-content/debug.log` (requires `WP_DEBUG_LOG`).

## Project Structure

```
.
├── gf-advanced-expiring-entries.php    # Main plugin bootstrap
├── readme.txt                          # WordPress.org-style readme
├── README.md                           # This file
├── uninstall.php                       # Cleanup on uninstall
├── admin
│   ├── assets
│   │   ├── feed-settings.css           # Admin styles (badges, log, summary)
│   │   └── feed-settings.js            # Datepicker, AJAX tools, live summary
│   └── views
│       └── entry-meta-box.php          # Entry detail sidebar meta box
├── includes
│   ├── class-gf-aee-addon.php          # Core Feed Add-On class
│   ├── class-gf-aee-dashboard.php      # Dashboard widget
│   ├── class-gf-aee-expiry-runner.php  # Executes expiry actions per entry
│   ├── class-gf-aee-feed-settings.php  # Feed settings field definitions
│   ├── class-gf-aee-log.php            # Expiry event audit log
│   ├── class-gf-aee-meta.php           # Entry meta CRUD helpers
│   ├── class-gf-aee-processor.php      # Computes expiry on submission
│   ├── class-gf-aee-scheduler.php      # Self-healing wp_cron scheduling
│   └── class-github-updater.php        # GitHub auto-updates
└── languages
    ├── gf-advanced-expiring-entries-fr_FR.mo   # French translation (binary)
    ├── gf-advanced-expiring-entries-fr_FR.po   # French translation (source)
    └── gf-advanced-expiring-entries.pot         # Translation template
```

## Changelog

### 1.3.0 - 2026-04-03
- **New:** Anonymize expiry action — wipes all field values from the entry while keeping the entry row itself, preserving submission counts and petition totals
- **New:** Optional sub-settings for anonymization: clear IP address and source URL, clear "Created by" user reference, delete uploaded files from disk
- **Improved:** Feed summary and feed list column now display the new Anonymize action

### 1.2.5 - 2026-03-30
- **Improved:** GitHub updater rewritten — "View details" modal now shows Description, Installation, FAQ, and Changelog tabs parsed from README.md using Parsedown
- **Improved:** "View details" thickbox link added to the plugin row on the Plugins page
- **Improved:** Sidebar in the plugin details modal shows "Requires Gravity Forms" for GF add-on compatibility

### 1.2.4 - 2026-03-12
- **New:** Expiry Log now shows future expirations — entries scheduled to expire are displayed in the same table with expiry date, feed action, and status badges
- **New:** Period filter in the Expiry Log: "All expirations" (default), "Past expirations", and "Future expirations" — live AJAX filtering like all other dropdowns
- **Improved:** When "Future expirations" is selected, the Action and Success filters are greyed out (disabled) since they don't apply
- **Improved:** Expiry Log form dropdown now only lists forms that have at least one Expiring Entries feed
- **Improved:** Expiry Log now displays 10 entries per page (down from 50) for faster loading
- **Improved:** Feed add-on short title is now translatable — French displays "Flux d'expiration" instead of "Flux Expiring Entries"

### 1.2.3 - 2026-03-11
- **New:** Custom "Expiring Entries" notification event — create dedicated GF notifications that won't fire on form submission, designed for pre-expiry, post-expiry, or expiry action use
- **Improved:** Retroactive tool form dropdown now only shows forms that have at least one active Expiring Entries feed
- **Improved:** Retroactive tool now includes a processing mode selector: process only entries without an expiry timestamp, or recompute all entries
- **Improved:** Retroactive tool description text updated to reflect the new processing mode option

### 1.2.2 - 2026-03-11
- **Improved:** When the form has a single date field, it is automatically preselected in the Date Field dropdown
- **Improved:** "Expire At" time picker is now visible for all three expiry types (including Fixed Date)
- **Improved:** Offset direction, value, and unit fields are now equal width for a cleaner layout

### 1.2.1 - 2026-03-11
- **Improved:** Expiry Type selector is now a button group (Entry Date / Date Field / Fixed Date) instead of radio buttons
- **Improved:** Entry Date is now the default expiry type
- **Improved:** Time offset is always visible for Date Field and Entry Date types (defaults to 0 minutes) — removed the "Add a time offset" checkbox
- **Improved:** Date Field button is automatically disabled (greyed out) when the form has no date fields
- **Improved:** Each expiry type button now shows a tooltip on hover explaining its purpose
- **Improved:** Replaced "Snap To" (Advanced options) with a clearer "Expire At" time-of-day picker — choose an exact hourly time instead of vague "start/end of day" snapping
- **Fixed:** Timezone mismatch — date field values and fixed dates were parsed as UTC instead of the WordPress timezone, causing wrong expiry times
- **Fixed:** Snap-to (now "Expire At") was applied after the offset instead of before, cancelling the offset when snapping to day boundaries

### 1.2.0 - 2026-03-11
- **Improved:** Merged Pre-Expiry, Post-Expiry (Success), and Post-Expiry (Fail) notification panels into a single "Notifications" section — same features, less visual noise
- **Improved:** Offset Direction, Value, and Unit fields now display on a single inline row instead of three separate rows
- **Improved:** "Snap To" option is now tucked behind an "Advanced options" toggle to reduce default complexity
- **Improved:** Renamed "Empty Date Fallback" section to "Missing Date Handling" for clarity

### 1.1.2 - 2026-03-10
- **Fixed:** Pre-expiry notifications could be sent after the entry had already expired when the notification timestamp was in the past (e.g. retroactive processing or short remaining time)
- **Fixed:** Setting a manual override date did not reschedule the pre-expiry notification for the new date
- **Fixed:** Dashboard widget did not count entries with a manual override (extended status) in Active and Expiring Soon totals
- **Fixed:** Uninstall cleanup was incomplete — now removes post-notification meta keys, single-fire cron events, and entry snapshot transients
- **Fixed:** Compatibility with the Members plugin — feed settings page was blank and submenu was missing from the forms list because the addon did not declare `$_capabilities`

### 1.1.1 - 2026-03-09
- **Fixed:** WordPress 6.7+ "Translation loading triggered too early" warning caused by `esc_html__()` in the `cron_schedules` filter
- **Fixed:** PHP "Undefined property: stdClass::$slug" warning on update-core.php — added missing `id`, `slug`, `plugin`, and `new_version` fields to the GitHub updater response

### 1.1.0 - 2026-03-05
- **New:** Post-Expiry Notifications — send a GF notification after a successful or failed expiry action, with configurable delay
- **New:** Entry data snapshot for post-expiry notifications — merge tags resolve correctly even when the entry was trashed or deleted
- **Improved:** Expiry Log now filters live via AJAX (removed the "Filter" button)
- **Improved:** Retroactive tool dropdowns displayed inline on a single line without labels
- **Improved:** Time and Unit fields displayed inline on a single line in all three notification panels

### 1.0.2 - 2026-03-04
- **Fixed:** Translation loading triggered too early warning on WordPress 6.7+ (`_load_textdomain_just_in_time`)

### 1.0.1 - 2026-03-03
- **Improved:** Retroactive tool now shows a dropdown of feed names instead of requiring a raw Feed ID

### 1.0.0 - 2026-03-03
- **New:** Initial release
- **New:** Fixed, dynamic, and entry-date expiry types with offset and day-snapping
- **New:** 6 expiry actions — Trash, Delete (with backup), Change Status, Update Field, Webhook, Notification
- **New:** Pre-expiry notifications with configurable lead time
- **New:** Per-entry override, extension, and exemption from entry detail sidebar
- **New:** Dashboard widget with active/expiring-soon/expired counts per form
- **New:** Dry-run mode for safe testing
- **New:** Retroactive processing tool for existing entries
- **New:** Conditional logic support on feeds
- **New:** Live AJAX feed summary in feed settings
- **New:** Full expiry audit log with filters and pagination
- **New:** Self-healing wp_cron with overdue event detection
- **New:** Colour-coded sortable "Expires" column in entry list
- **New:** GitHub auto-updater for seamless updates
- **New:** French translation

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with love for the WordPress community
</p>
