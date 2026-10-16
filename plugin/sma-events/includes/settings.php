<?php
// Plugin settings: one autoloaded "sma_<key>" option each, read through sma_setting(), and the admin-only Settings screen.

if (!defined('ABSPATH')) {
    exit;
}

const SMA_SETTINGS_SLUG = 'sma-settings';

/** Every setting with its default. */
function sma_setting_defaults()
{
    return [
        'signup_form_id'          => 0,     // 0: detect by title prefix (sma_find_form_id)
        'volunteer_form_id'       => 0,     // 0: detect by title prefix
        'booking_calendar_id'     => 0,     // 0: the first FluentBooking calendar
        'session_minutes'         => 10,
        'reminder_days'           => [14, 7, 3, 1],
        'public_events_list'      => true,
        'editors_manage_families' => false,
        'approval_nudge_days'     => 3,     // families waiting longer than this are called out
        'digest_enabled'          => true,  // the daily digest email (digest.php)
        'digest_time'             => '07:00', // HH:MM, site time zone
        'digest_recipient'        => '',    // '': the site's admin email
    ];
}

/** The option name that stores a setting (the two form options keep their existing names). */
function sma_setting_option_name($key)
{
    if ($key === 'signup_form_id') {
        return SMA_SIGNUP_FORM_OPTION;
    }
    if ($key === 'volunteer_form_id') {
        return SMA_VOLUNTEER_FORM_OPTION;
    }
    return 'sma_' . $key;
}

/** Reminder days from a comma list or array: whole numbers 1–60, unique, largest first. */
function sma_setting_parse_days($value)
{
    $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
    $days  = [];
    foreach ((array) $parts as $part) {
        $part = trim((string) $part);
        if (!preg_match('/^\d{1,3}$/', $part)) {
            continue;
        }
        $day = (int) $part;
        if ($day >= 1 && $day <= 60) {
            $days[$day] = $day;
        }
    }
    $days = array_values($days);
    rsort($days, SORT_NUMERIC);
    return $days;
}

/** A value cleaned for its key, or null when it can't be used. */
function sma_setting_sanitize($key, $value)
{
    switch ($key) {
        case 'signup_form_id':
        case 'volunteer_form_id':
        case 'booking_calendar_id':
            if (!is_numeric($value)) {
                return null;
            }
            return max(0, (int) $value);

        case 'session_minutes':
            if (!is_numeric($value)) {
                return null;
            }
            return min(120, max(5, (int) $value));

        case 'approval_nudge_days':
            if (!is_numeric($value)) {
                return null;
            }
            return min(60, max(1, (int) $value));

        case 'reminder_days':
            $days = sma_setting_parse_days($value);
            return $days ? $days : null;

        case 'digest_time':
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', (string) $value, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
                return null;
            }
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);

        case 'digest_recipient':
            $email = trim((string) $value);
            if ($email === '') {
                return '';
            }
            $email = sanitize_email($email);
            return ($email && is_email($email)) ? $email : null;

        case 'public_events_list':
        case 'editors_manage_families':
        case 'digest_enabled':
            if (is_bool($value)) {
                return $value;
            }
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
    return null;
}

/** A plugin setting with its default applied. Unknown key: null. */
function sma_setting($key)
{
    $defaults = sma_setting_defaults();
    if (!array_key_exists($key, $defaults)) {
        return null;
    }
    $stored = get_option(sma_setting_option_name($key), null);
    if ($stored === null || $stored === false) {
        return $defaults[$key];
    }
    $clean = sma_setting_sanitize($key, $stored);
    return $clean === null ? $defaults[$key] : $clean;
}

/** Save one cleaned setting (false if unknown or unusable), with side effects for forms and the editors setting. */
function sma_update_setting($key, $value)
{
    if (!array_key_exists($key, sma_setting_defaults())) {
        return false;
    }
    $clean = sma_setting_sanitize($key, $value);
    if ($clean === null) {
        return false;
    }

    if ($key === 'reminder_days') {
        $stored = implode(',', $clean);
    } elseif (is_bool($clean)) {
        $stored = $clean ? 1 : 0;
    } else {
        $stored = $clean;
    }
    $option = sma_setting_option_name($key);
    update_option($option, $stored, true);

    if ($key === 'signup_form_id' || $key === 'volunteer_form_id') {
        // The cache key sma_find_form_id() uses (registration.php).
        delete_transient($option . '_auto');
    }
    if ($key === 'editors_manage_families') {
        sma_settings_sync_editor_cap();
    }
    return true;
}

/** Give the editor role sma_manage_families only while the setting allows it. */
function sma_settings_sync_editor_cap()
{
    $editor = get_role('editor');
    if (!$editor) {
        return;
    }
    if (sma_setting('editors_manage_families')) {
        $editor->add_cap(SMA_FAMILIES_CAP);
    } else {
        $editor->remove_cap(SMA_FAMILIES_CAP);
    }
}

/* ------------------------------------------------------------------------------- the screen */

// After sma_dash_menu (priority 10), so the parent menu exists.
add_action('admin_menu', 'sma_settings_menu', 20);

function sma_settings_menu()
{
    // The top-level menu links to its first submenu, so the dashboard is listed first.
    add_submenu_page(
        SMA_DASH_SLUG,
        'So Many Angels',
        'Dashboard',
        current_user_can(SMA_FAMILIES_CAP) ? SMA_FAMILIES_CAP : 'manage_options',
        SMA_DASH_SLUG,
        ''
    );
    $GLOBALS['sma_settings_hook'] = add_submenu_page(
        SMA_DASH_SLUG,
        'So Many Angels settings',
        'Settings',
        'manage_options',
        SMA_SETTINGS_SLUG,
        'sma_settings_render'
    );
}

add_action('admin_enqueue_scripts', 'sma_settings_assets');

function sma_settings_assets($hook)
{
    if (!empty($GLOBALS['sma_settings_hook']) && $hook === $GLOBALS['sma_settings_hook']) {
        sma_enqueue_css();
    }
}

/** Fluent Forms forms as [id => title], published first, or null without Fluent Forms. */
function sma_settings_forms()
{
    if (!defined('FLUENTFORM')) {
        return null;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fluentform_forms';
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title FROM {$table} ORDER BY CASE WHEN status = %s THEN 0 ELSE 1 END, id ASC",
        'published'
    ));
    $forms = [];
    foreach ((array) $rows as $row) {
        $forms[(int) $row->id] = (string) $row->title;
    }
    return $forms;
}

/** FluentBooking calendars as [id => title], or null without FluentBooking. */
function sma_settings_calendars()
{
    if (!class_exists('\FluentBooking\App\Models\Calendar')) {
        return null;
    }
    try {
        $rows = \FluentBooking\App\Models\Calendar::query()->orderBy('id', 'ASC')->get(['id', 'title']);
    } catch (\Throwable $e) {
        return null;
    }
    $calendars = [];
    foreach ($rows as $row) {
        $calendars[(int) $row->id] = (string) $row->title;
    }
    return $calendars;
}

/** The notice for ?sma_notice=..., as [type, message] or null. */
function sma_settings_notice()
{
    $notice = isset($_GET['sma_notice']) ? sanitize_key(wp_unslash($_GET['sma_notice'])) : '';
    $messages = [
        'saved'        => ['success', 'Settings saved.'],
        'days_invalid' => ['warning', 'Settings saved, except the reminder days: enter whole numbers from 1 to 60, like 14, 7, 3, 1.'],
        'digest_invalid' => ['warning', 'Settings saved, except the digest time or email: enter a time like 07:00 and a full email address, or leave the email empty.'],
        'digest_sent'    => ['success', 'Test digest sent to ' . sma_digest_recipient() . '.'],
        'digest_failed'  => ['error', 'The test digest could not be sent. Check that FluentSMTP is set up (see Site checks below).'],
    ];
    return isset($messages[$notice]) ? $messages[$notice] : null;
}

function sma_settings_render()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
    }

    $values = [];
    foreach (array_keys(sma_setting_defaults()) as $key) {
        $values[$key] = sma_setting($key);
    }

    sma_include_view(SMA_EVENTS_DIR . '/admin/settings.php', [
        'values'    => $values,
        'forms'     => sma_settings_forms(),
        'calendars' => sma_settings_calendars(),
        // The form actually used now: the saved one, else the one found by title.
        'effective' => [
            'signup_form_id'    => sma_signup_form_id(),
            'volunteer_form_id' => sma_volunteer_form_id(),
        ],
        'notice'    => sma_settings_notice(),
        'digest'    => [
            'recipient' => sma_digest_recipient(),
            'next'      => sma_digest_next_label(),
        ],
        'checks'    => sma_site_checks(), // site-checks.php
    ]);
}

/* --------------------------------------------------------------------------------- the save */

add_action('admin_post_sma_settings_save', 'sma_settings_save');

function sma_settings_save()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
    }
    check_admin_referer('sma_settings');

    $post = wp_unslash($_POST);
    foreach (['signup_form_id', 'volunteer_form_id', 'booking_calendar_id', 'session_minutes'] as $key) {
        if (isset($post[$key])) {
            sma_update_setting($key, sanitize_text_field((string) $post[$key]));
        }
    }
    // Unticked checkboxes are not posted.
    sma_update_setting('public_events_list', !empty($post['public_events_list']));
    sma_update_setting('editors_manage_families', !empty($post['editors_manage_families']));

    $notice = 'saved';
    if (isset($post['reminder_days'])
        && !sma_update_setting('reminder_days', sanitize_text_field((string) $post['reminder_days']))) {
        $notice = 'days_invalid';
    }

    // Digest fields change only when posted (the checkbox has a hidden "0" before it).
    if (isset($post['digest_enabled'])) {
        sma_update_setting('digest_enabled', !empty($post['digest_enabled']));
    }
    if (isset($post['approval_nudge_days'])) {
        sma_update_setting('approval_nudge_days', sanitize_text_field((string) $post['approval_nudge_days']));
    }
    foreach (['digest_time', 'digest_recipient'] as $key) {
        if (isset($post[$key]) && !sma_update_setting($key, sanitize_text_field((string) $post[$key])) && $notice === 'saved') {
            $notice = 'digest_invalid';
        }
    }
    if (function_exists('sma_digest_schedule')) {
        sma_digest_schedule();
    }

    wp_safe_redirect(add_query_arg(
        ['page' => SMA_SETTINGS_SLUG, 'sma_notice' => $notice],
        admin_url('admin.php')
    ));
    exit;
}
