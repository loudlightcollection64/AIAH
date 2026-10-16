<?php
/**
 * Plugin Name: SMA Events
 * Description: One event record for So Many Angels. Forms and pages read their event list from it, so past events drop off on their own. Sign-ups become CRM contacts awaiting approval; approval emails the booking link.
 * Version: 0.4.0
 * Requires PHP: 7.4
 * Author: AIAH 2026 team
 */

if (!defined('ABSPATH')) {
    exit;
}

const SMA_EVENTS_VERSION = '0.4.0';
const SMA_EVENTS_DIR = __DIR__;

const SMA_EVENTS_POST_TYPE = 'sma_event';

// Field name a Fluent Forms dropdown must use to be filled with open events.
const SMA_EVENTS_FORM_FIELD = 'event';

// Dropdown options whose value starts with this are kept as-is, e.g. "always-individual".
const SMA_EVENTS_KEEP_PREFIX = 'always-';

// Forms are matched by an option holding the form ID, else by title prefix. Never by a fixed ID.
const SMA_SIGNUP_FORM_OPTION = 'sma_signup_form_id';
const SMA_SIGNUP_FORM_PREFIX = 'SMA Session Signup';
const SMA_VOLUNTEER_FORM_OPTION = 'sma_volunteer_form_id';
const SMA_VOLUNTEER_FORM_PREFIX = 'SMA Volunteer Application';

// Who may see and change family and volunteer data: administrators, and editors only when Settings allows.
const SMA_FAMILIES_CAP = 'sma_manage_families';

const SMA_EVENTS_META = [
    'city'                => 'string',
    'venue'               => 'string',
    'address'             => 'string',
    'event_date'          => 'string',  // YYYY-MM-DD
    'start_time'          => 'string',  // HH:MM, 24-hour, venue local time
    'end_time'            => 'string',
    'timezone'            => 'string',  // e.g. America/New_York
    'capacity'            => 'integer', // number of sessions
    'canvas_handover'     => 'string',  // how the family receives the canvas
    'booking_calendar_id' => 'integer', // FluentBooking calendar
    'booking_session_id'  => 'integer', // FluentBooking event (session type) inside that calendar
    'booking_url'         => 'string',  // link approved families use to pick a time
    'crm_tag_id'          => 'integer', // FluentCRM tag "event-<slug>", created by the plugin
    'booking_page_id'     => 'integer', // plugin-managed page holding the booking widget
    'sma_provision_error' => 'string',  // plain sentence when set-up failed, else empty
    'sma_provisioned_at'  => 'string',  // last full set-up, site time (Y-m-d H:i:s)
];

// Every file is required (a missing one fails loudly); the order sets hook order on equal priorities.
require_once SMA_EVENTS_DIR . '/includes/event-record.php';
require_once SMA_EVENTS_DIR . '/includes/settings.php';
require_once SMA_EVENTS_DIR . '/includes/stages.php';
require_once SMA_EVENTS_DIR . '/includes/form-fields.php';
require_once SMA_EVENTS_DIR . '/includes/emails.php';
require_once SMA_EVENTS_DIR . '/includes/provisioning.php';
require_once SMA_EVENTS_DIR . '/includes/registration.php';
require_once SMA_EVENTS_DIR . '/includes/approval.php';
require_once SMA_EVENTS_DIR . '/includes/volunteers.php';
require_once SMA_EVENTS_DIR . '/includes/rest.php';
require_once SMA_EVENTS_DIR . '/includes/pages.php';
require_once SMA_EVENTS_DIR . '/includes/shortcode.php';
require_once SMA_EVENTS_DIR . '/includes/canvas.php';
require_once SMA_EVENTS_DIR . '/includes/families.php';
require_once SMA_EVENTS_DIR . '/includes/dashboard.php';
require_once SMA_EVENTS_DIR . '/includes/dashboard-actions.php';
require_once SMA_EVENTS_DIR . '/includes/export.php';
require_once SMA_EVENTS_DIR . '/includes/digest.php';
require_once SMA_EVENTS_DIR . '/includes/site-checks.php';

register_activation_hook(__FILE__, 'sma_events_activate');
register_deactivation_hook(__FILE__, 'sma_digest_unschedule');
