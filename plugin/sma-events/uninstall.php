<?php
// On plugin deletion: remove its role, capabilities, options, transients and cron; leave all events, entries, contacts and bookings.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sma_caps = [
    'edit_sma_events',
    'edit_others_sma_events',
    'edit_private_sma_events',
    'edit_published_sma_events',
    'publish_sma_events',
    'read_private_sma_events',
    'delete_sma_events',
    'delete_others_sma_events',
    'delete_private_sma_events',
    'delete_published_sma_events',
    'sma_manage_families',
];
foreach (['administrator', 'editor'] as $sma_role_name) {
    $sma_role = get_role($sma_role_name);
    if ($sma_role) {
        foreach ($sma_caps as $sma_cap) {
            $sma_role->remove_cap($sma_cap);
        }
    }
}
remove_role('sma_agent');

foreach ([
    'sma_signup_form_id',
    'sma_volunteer_form_id',
    'sma_booking_calendar_id',
    'sma_session_minutes',
    'sma_reminder_days',
    'sma_public_events_list',
    'sma_editors_manage_families',
    'sma_approval_nudge_days',
    'sma_decline_drafts',
    'sma_caps_version',
    'sma_families_cap_version',
    'sma_pages_rewrite_version',
] as $sma_option) {
    delete_option($sma_option);
}

// Daily digest settings, last-sent time and cron event.
foreach (['sma_digest_enabled', 'sma_digest_time', 'sma_digest_recipient', 'sma_digest_last_sent', 'sma_approval_nudge_days'] as $sma_option) {
    delete_option($sma_option);
}
wp_clear_scheduled_hook('sma_digest_daily');

foreach (['sma_signup_form_id_auto', 'sma_volunteer_form_id_auto', 'sma_pages_rewrite_healed'] as $sma_transient) {
    delete_transient($sma_transient);
}

// One "please check these fields" transient per user (60 seconds each, so usually gone already).
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_sma_event_bad_fields_') . '%',
    $wpdb->esc_like('_transient_timeout_sma_event_bad_fields_') . '%'
));

// The plugin's /event/ and /events/ rules are rebuilt without it on the next request.
delete_option('rewrite_rules');
