<?php
// REST routes (sma/v1) for users with the families capability; responses carry ids, stages and counts only.

if (!defined('ABSPATH')) {
    exit;
}

/** True when the current user may see and change family and volunteer data. */
function sma_can_manage_families()
{
    // manage_options covers an administrator before the capability grant has run.
    return current_user_can(SMA_FAMILIES_CAP) || current_user_can('manage_options');
}

add_action('rest_api_init', 'sma_rest_register');

function sma_rest_can_edit()
{
    return sma_can_manage_families();
}

/** The contact for a stage lookup, by contact_id or else by email: Subscriber or WP_Error. */
function sma_rest_contact_from_request(WP_REST_Request $request)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.', ['status' => 503]);
    }
    $contact_id = (int) $request['contact_id'];
    $email      = (string) $request['email'];
    if ($contact_id <= 0 && $email === '') {
        return new WP_Error('sma_no_contact', 'Give contact_id or email.', ['status' => 400]);
    }
    // An email in a GET query string would land in server access logs.
    if ($contact_id <= 0 && $request->get_method() !== 'POST') {
        return new WP_Error('sma_email_needs_post', 'Look up by email with POST, not in the URL.', ['status' => 400]);
    }
    $contact = $contact_id > 0 ? sma_contact($contact_id) : sma_contact_by_email($email);
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'Contact not found.', ['status' => 404]);
    }
    return $contact;
}

/** Args for the stage lookup: contact_id (GET or POST) or email (POST only). */
function sma_rest_contact_lookup_args()
{
    return [
        'contact_id' => [
            'required'          => false,
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ],
        'email' => [
            'required'          => false,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_email',
        ],
    ];
}

/** Routes that email someone at once exist only on the local copy; live, every such email is previewed first. */
function sma_rest_send_routes_enabled()
{
    return function_exists('sma_dev_is_local') && sma_dev_is_local();
}

function sma_rest_register()
{
    $contact_arg = [
        'required'          => true,
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
    ];
    $event_arg = [
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_title',
    ];

    // Emails the booking link at once, so local copy only.
    if (sma_rest_send_routes_enabled()) {
        register_rest_route('sma/v1', '/families/approve', [
            'methods'             => 'POST',
            'permission_callback' => 'sma_rest_can_edit',
            'args'                => ['contact_id' => $contact_arg, 'event' => $event_arg],
            'callback'            => function (WP_REST_Request $request) {
                $result = sma_approve_family($request['contact_id'], $request['event']);
                return sma_rest_decision_response($result, $request);
            },
        ]);
    }

    register_rest_route('sma/v1', '/families/decline', [
        'methods'             => 'POST',
        'permission_callback' => 'sma_rest_can_edit',
        'args'                => [
            'contact_id' => $contact_arg,
            'event'      => $event_arg,
            'reason'     => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
        ],
        'callback'            => function (WP_REST_Request $request) {
            $result = sma_decline_family($request['contact_id'], $request['event'], $request['reason']);
            return sma_rest_decision_response($result, $request);
        },
    ]);

    register_rest_route('sma/v1', '/families/stage', [
        'methods'             => ['GET', 'POST'],
        'permission_callback' => 'sma_rest_can_edit',
        'args'                => sma_rest_contact_lookup_args(),
        'callback'            => function (WP_REST_Request $request) {
            $contact = sma_rest_contact_from_request($request);
            if (is_wp_error($contact)) {
                return $contact;
            }
            // stage: latest stage tag; stages: per-event stages; entries: sign-up ids and flags per event.
            $stages  = sma_event_stages($contact);
            $entries = sma_rest_entries_shape((int) $contact->id);
            return [
                'contact_id'      => (int) $contact->id,
                'stage'           => sma_get_stage($contact, 'family'),
                'stages'          => $stages ? $stages : new stdClass(),
                'volunteer_stage' => sma_get_stage($contact, 'volunteer'),
                'events'          => sma_contact_events($contact),
                'entries'         => $entries ? $entries : new stdClass(),
            ];
        },
    ]);

    register_rest_route('sma/v1', '/events/(?P<slug>[a-z0-9-]+)/counts', [
        'methods'             => 'GET',
        'permission_callback' => 'sma_rest_can_edit',
        'callback'            => function (WP_REST_Request $request) {
            $slug = sanitize_title($request['slug']);
            if (!sma_event_by_slug($slug)) {
                return new WP_Error('sma_no_event', 'Event not found.', ['status' => 404]);
            }
            return sma_event_stage_counts($slug);
        },
    ]);
}

/** A contact's sign-up entries: event slug (or "_none") => [{id, flags}], oldest first, no content. */
function sma_rest_entries_shape($contact_id)
{
    $records = sma_entry_records($contact_id);
    $out = [];
    foreach (sma_signup_map($contact_id) as $key => $ids) {
        foreach ($ids as $id) {
            $flags = isset($records[(string) $id]['flags']) && is_array($records[(string) $id]['flags'])
                ? array_values($records[(string) $id]['flags'])
                : [];
            $out[(string) $key][] = ['id' => (int) $id, 'flags' => $flags];
        }
    }
    return $out;
}

function sma_rest_decision_response($result, WP_REST_Request $request)
{
    if (is_wp_error($result)) {
        return $result;
    }
    $contact = sma_contact((int) $request['contact_id']);
    return [
        'ok'         => true,
        'contact_id' => (int) $request['contact_id'],
        'event'      => sanitize_title($request['event']),
        'stage'      => $contact ? sma_get_stage($contact, 'family') : '',
    ];
}
