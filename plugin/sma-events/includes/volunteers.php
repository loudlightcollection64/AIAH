<?php
// Volunteers: application, approval (with details email) and decline (drafted, never sent); callers check capabilities.

if (!defined('ABSPATH')) {
    exit;
}

// Contact meta: the latest application as a profile, plus events: { key: { entry_id, role, applied_at, status, decided_at, thanked_at } }.
const SMA_VOLUNTEER_META = 'sma_volunteer';

/** Like sma_field(), but a multi-value field (checkboxes, uploads) comes back joined by ", ". */
function sma_volunteer_field(array $data, array $names)
{
    $value = sma_field($data, $names);
    if ($value !== '') {
        return $value;
    }
    foreach ($names as $name) {
        $raw = sma_field_value($data, $name);
        if (is_array($raw)) {
            $parts = array_filter(array_map('trim', array_filter($raw, 'is_scalar')), 'strlen');
            if ($parts) {
                return implode(', ', $parts);
            }
        }
    }
    return '';
}

/** Role slug → label, matching the volunteer form's options; unknown slugs are title-cased. */
function sma_volunteer_role_label($role)
{
    $role   = (string) $role;
    $labels = apply_filters('sma_volunteer_role_labels', [
        'station-photographer'           => 'Station Photographer',
        'behind-the-scenes-photographer' => 'Behind The Scenes Photographer',
        'videographer'                   => 'Videographer',
        'social-media-content-creator'   => 'Social Media Content Creator',
        'check-in-volunteer'             => 'Check-In Volunteer',
        'cosplay-artist'                 => 'Cosplay Artist',
        'floater'                        => 'Floater',
        'as-needed'                      => 'As Needed',
    ]);
    if (isset($labels[$role])) {
        return $labels[$role];
    }
    return ucwords(str_replace(['-', '_'], ' ', $role));
}

/** What a volunteer brings, by role slug: editable defaults (filter "sma_volunteer_bring_by_role"). */
function sma_volunteer_bring_by_role()
{
    $photographer = 'your camera (one with two card slots if you have it), a portrait lens, portable lighting, comfortable shoes and water';
    return apply_filters('sma_volunteer_bring_by_role', [
        'station-photographer'           => $photographer,
        'behind-the-scenes-photographer' => $photographer,
        'videographer'                   => 'your video camera with charged batteries, comfortable shoes and water',
        'default'                        => 'comfortable shoes and water',
    ]);
}

function sma_volunteer_what_to_bring($role)
{
    $map = sma_volunteer_bring_by_role();
    if (isset($map[$role]) && is_string($map[$role])) {
        return $map[$role];
    }
    return isset($map['default']) && is_string($map['default']) ? $map['default'] : '';
}

/* ------------------------------------------------ submission ------------------------------------------------ */

add_action('fluentform/submission_inserted', 'sma_on_volunteer_submission', 20, 3);

function sma_on_volunteer_submission($entry_id, $form_data, $form)
{
    $form_id = (is_object($form) && isset($form->id)) ? (int) $form->id : 0;
    if (!$form_id || !is_array($form_data) || $form_id !== sma_volunteer_form_id()) {
        return;
    }

    try {
        $result = sma_register_volunteer((int) $entry_id, $form_data);
        if (is_wp_error($result)) {
            error_log('sma: volunteer entry ' . (int) $entry_id . ' not linked: ' . $result->get_error_code());
        }
    } catch (\Throwable $e) {
        error_log('sma: volunteer entry ' . (int) $entry_id . ' failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

/** The volunteer profile meta, always an array with an "events" array. */
function sma_volunteer_profile($contact_id)
{
    $profile = sma_contact_meta_get((int) $contact_id, SMA_VOLUNTEER_META, []);
    if (!is_array($profile)) {
        $profile = [];
    }
    if (!isset($profile['events']) || !is_array($profile['events'])) {
        $profile['events'] = [];
    }
    return $profile;
}

/** First URL from an upload field value (a list of URLs), else ''. */
function sma_volunteer_upload_url($value)
{
    $candidates = is_array($value) ? $value : explode(',', (string) $value);
    foreach ($candidates as $candidate) {
        if (is_scalar($candidate)) {
            $url = esc_url_raw(trim((string) $candidate));
            if ($url !== '') {
                return $url;
            }
        }
    }
    return '';
}

/** Register an application: contact, event tag, volunteer-applied, acknowledgement; returns the contact id. */
function sma_register_volunteer($entry_id, array $data)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }

    $fields = sma_volunteer_fields();
    $email  = sanitize_email(sma_field($data, $fields['email']));
    if (!$email || !is_email($email)) {
        return new WP_Error('sma_no_email', 'The application has no valid email address.');
    }

    $first      = sanitize_text_field(sma_field($data, $fields['first_name']));
    $event_slug = sanitize_title(sma_field($data, $fields['event']));
    $role       = sanitize_title(sma_volunteer_field($data, $fields['role']));
    $location   = sanitize_title(sma_field($data, $fields['preferred_location']));

    $file_value = null;
    foreach ($fields['portfolio_file'] as $name) {
        if (isset($data[$name])) {
            $file_value = $data[$name];
            break;
        }
    }

    // Same rules as a family sign-up: an existing contact's details are never changed.
    $upsert = sma_upsert_contact([
        'email'      => $email,
        'first_name' => $first,
        'last_name'  => sanitize_text_field(sma_field($data, $fields['last_name'])),
        'phone'      => sanitize_text_field(sma_field($data, $fields['phone'])),
    ]);
    if (is_wp_error($upsert)) {
        return $upsert;
    }
    $contact    = $upsert['contact'];
    $contact_id = (int) $contact->id;

    $previous_events = sma_contact_events($contact);

    // Anything that isn't an event record is "_none".
    $event = sma_choice_event($event_slug);
    if ($event) {
        $tag_id = sma_event_ensure_tag($event);
        if ($tag_id) {
            $contact->attachTags([$tag_id]);
        }
    }

    // Already approved for this event: keep the stage, no second acknowledgement.
    $key     = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
    $profile = sma_volunteer_profile($contact_id);
    $repeat  = $event
        && in_array($event->post_name, $previous_events, true)
        && isset($profile['events'][$key]['status'])
        && $profile['events'][$key]['status'] === 'approved';

    if (!$repeat) {
        $staged = sma_set_stage($contact, 'volunteer-applied');
        if (is_wp_error($staged)) {
            return $staged;
        }
    }

    $now = current_time('mysql');
    $profile = array_merge($profile, [
        'role'               => $role,
        'tshirt_size'        => sanitize_text_field(sma_field($data, $fields['tshirt_size'])),
        'preferred_location' => $location,
        'portfolio_url'      => esc_url_raw(sma_field($data, $fields['portfolio_url'])),
        'portfolio_file'     => sma_volunteer_upload_url($file_value),
        'last_entry_id'      => (int) $entry_id,
        'applied_at'         => $now,
    ]);
    $previous = isset($profile['events'][$key]) && is_array($profile['events'][$key]) ? $profile['events'][$key] : [];
    $profile['events'][$key] = array_merge($previous, [
        'entry_id'   => (int) $entry_id,
        'role'       => $role,
        'applied_at' => $now,
        'status'     => $repeat ? 'approved' : 'applied',
    ]);
    sma_contact_meta_set($contact_id, SMA_VOLUNTEER_META, $profile);

    sma_add_contact_note(
        $contact_id,
        $repeat ? 'Volunteer applied again (stage kept)' : 'Volunteer application received',
        'Volunteer form entry #' . (int) $entry_id . ($event ? ' for ' . $event->post_name : '') . '.'
    );

    if (!$repeat) {
        $details = $event ? sma_event_details($event) : [];
        $sent = sma_send_email($email, 'volunteer-received', [
            'first_name'  => $first !== '' ? $first : 'there',
            'role'        => $role !== '' ? sma_volunteer_role_label($role) : '',
            'event_label' => isset($details['label']) ? $details['label'] : '',
        ]);
        if (is_wp_error($sent)) {
            error_log('sma: volunteer acknowledgement not sent for entry ' . (int) $entry_id . ': ' . $sent->get_error_code());
        }
    }

    return $contact_id;
}

/* ------------------------------------------------ approve / decline ------------------------------------------------ */

/** [contact, event] for a volunteer decision; the contact must have applied as a volunteer. */
function sma_volunteer_decision_targets($contact_id, $event_slug)
{
    // Check before sma_decision_targets(), which attaches the event tag.
    $contact = sma_contact((int) $contact_id);
    if ($contact) {
        $profile = sma_volunteer_profile($contact->id);
        $is_volunteer = sma_get_stage($contact, 'volunteer') !== '' || !empty($profile['last_entry_id']);
        if (!$is_volunteer) {
            return new WP_Error('sma_not_volunteer', 'This contact has not applied to volunteer.', ['status' => 409]);
        }
    }
    return sma_decision_targets($contact_id, $event_slug);
}

/** Arrival time label: $override if given ("HH:MM" or free text), else 30 minutes before start. */
function sma_volunteer_arrival_time(WP_Post $event, $override = '')
{
    $override = trim(sanitize_text_field((string) $override));
    if ($override !== '') {
        return preg_match('/^\d{1,2}:\d{2}$/', $override) ? sma_events_time_label($override) : $override;
    }

    $start = trim((string) get_post_meta($event->ID, 'start_time', true));
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $start, $m)) {
        return '';
    }
    $minutes = max(0, (int) $m[1] * 60 + (int) $m[2] - (int) apply_filters('sma_volunteer_arrival_minutes_before', 30, $event));
    return sma_events_time_label(sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60));
}

/** Email variables for a volunteer and event: first name, role and event details only. */
function sma_volunteer_email_vars($contact, WP_Post $event, array $extra = [])
{
    $profile = sma_volunteer_profile($contact->id);
    $slug    = $event->post_name;
    $role    = isset($profile['events'][$slug]['role']) && $profile['events'][$slug]['role'] !== ''
        ? (string) $profile['events'][$slug]['role']
        : (isset($profile['role']) ? (string) $profile['role'] : '');
    if (!empty($extra['role'])) {
        $role = sanitize_title((string) $extra['role']);
    }
    $first = trim((string) $contact->first_name);

    $bring = isset($extra['what_to_bring']) && trim((string) $extra['what_to_bring']) !== ''
        ? sanitize_text_field((string) $extra['what_to_bring'])
        : sma_volunteer_what_to_bring($role);

    return array_merge(sma_event_details($event), [
        'first_name'    => $first !== '' ? $first : 'there',
        'role'          => $role !== '' ? sma_volunteer_role_label($role) : '',
        'arrival_time'  => sma_volunteer_arrival_time($event, isset($extra['arrival_time']) ? $extra['arrival_time'] : ''),
        'what_to_bring' => $bring,
    ]);
}

/** Store the per-event status in the volunteer profile. */
function sma_volunteer_mark($contact_id, $event_slug, $status)
{
    $profile = sma_volunteer_profile($contact_id);
    $entry   = isset($profile['events'][$event_slug]) && is_array($profile['events'][$event_slug]) ? $profile['events'][$event_slug] : [];
    $entry['status']     = $status;
    $entry['decided_at'] = current_time('mysql');
    $profile['events'][$event_slug] = $entry;
    sma_contact_meta_set($contact_id, SMA_VOLUNTEER_META, $profile);
}

/** Approve a volunteer and email where, when, what to bring and their role ($extra: arrival_time, what_to_bring, role). */
function sma_approve_volunteer($contact_id, $event_slug, $extra = [])
{
    $targets = sma_volunteer_decision_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event) = $targets;
    $slug = $event->post_name;
    $vars = sma_volunteer_email_vars($contact, $event, is_array($extra) ? $extra : []);

    $staged = sma_set_stage($contact, 'volunteer-approved');
    if (is_wp_error($staged)) {
        return $staged;
    }
    sma_volunteer_mark($contact->id, $slug, 'approved');

    $sent = sma_send_email($contact->email, 'volunteer-approved', $vars);
    if (is_wp_error($sent)) {
        sma_record_decision($contact->id, 'volunteer:' . $slug, 'volunteer-approved', 'Approved, but the confirmation email failed.');
        sma_add_contact_note($contact->id, 'Volunteer confirmation email failed', 'Approved to volunteer at ' . $slug . ', but the confirmation email could not be sent.');
        return $sent;
    }

    sma_record_decision($contact->id, 'volunteer:' . $slug, 'volunteer-approved', $vars['role']);
    sma_add_contact_note($contact->id, 'Volunteer approved', 'Approved to volunteer at ' . $slug . ' by ' . sma_current_user_label() . '. Confirmation email sent.');

    return true;
}

/** Decline a volunteer for an event; a note is drafted, nothing is sent. */
function sma_decline_volunteer($contact_id, $event_slug, $reason = '')
{
    $targets = sma_volunteer_decision_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event) = $targets;
    $slug   = $event->post_name;
    $reason = sanitize_textarea_field((string) $reason);

    $staged = sma_set_stage($contact, 'volunteer-declined');
    if (is_wp_error($staged)) {
        return $staged;
    }
    sma_volunteer_mark($contact->id, $slug, 'declined');

    $draft = sma_render_email('volunteer-declined', sma_volunteer_email_vars($contact, $event));
    if (is_wp_error($draft)) {
        return $draft;
    }

    // Never sent; it names only the volunteer, so it can live in their CRM notes.
    sma_add_draft_note($contact->id, 'Volunteer decline email draft (not sent)', $draft);

    sma_record_decision($contact->id, 'volunteer:' . $slug, 'volunteer-declined', $reason);
    sma_add_contact_note(
        $contact->id,
        'Volunteer declined',
        'Declined to volunteer at ' . $slug . ' by ' . sma_current_user_label() . '.' . ($reason !== '' ? "\nReason (internal): " . $reason : '')
    );

    return true;
}

/** Send the thank-you email to an approved volunteer, once per event unless $again. */
function sma_thank_volunteer($contact_id, $event_slug, $again = false)
{
    $targets = sma_volunteer_decision_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event) = $targets;
    $slug    = $event->post_name;
    $profile = sma_volunteer_profile($contact->id);
    $entry   = isset($profile['events'][$slug]) && is_array($profile['events'][$slug]) ? $profile['events'][$slug] : [];

    if (($entry['status'] ?? '') !== 'approved') {
        return new WP_Error('sma_not_approved', 'Only a volunteer approved for this event can be thanked.', ['status' => 409]);
    }
    if (!$again && !empty($entry['thanked_at'])) {
        return new WP_Error('sma_already_thanked', 'This volunteer has already been thanked for this event.', ['status' => 409]);
    }

    $sent = sma_send_email($contact->email, 'volunteer-thank-you', sma_volunteer_email_vars($contact, $event));
    if (is_wp_error($sent)) {
        return $sent;
    }

    // Re-read: the profile is one JSON value, written whole.
    $profile = sma_volunteer_profile($contact->id);
    $entry   = isset($profile['events'][$slug]) && is_array($profile['events'][$slug]) ? $profile['events'][$slug] : [];
    $entry['thanked_at'] = current_time('mysql');
    $profile['events'][$slug] = $entry;
    sma_contact_meta_set($contact->id, SMA_VOLUNTEER_META, $profile);
    sma_add_contact_note($contact->id, 'Volunteer thank-you sent', 'Thank-you email for ' . $slug . ' sent by ' . sma_current_user_label() . '.');

    return true;
}

/* ------------------------------------------------ lists for the dashboard and CSV (volunteer data only) ------------------------------------------------ */

/** The volunteer stage slug for a per-event status ("applied" → "volunteer-applied"), or ''. */
function sma_volunteer_status_stage($status)
{
    $stage = 'volunteer-' . (string) $status;
    $all   = sma_stage_tags('volunteer');
    return isset($all[$stage]) ? $stage : '';
}

/** Contact ids of volunteers; with an event slug, only those whose profile has an entry for it. */
function sma_volunteer_contact_ids($event_slug = '')
{
    if (!sma_crm_ready()) {
        return [];
    }

    $stage_ids = array_values(sma_tag_ids_by_slugs(array_keys(sma_stage_tags('volunteer'))));
    if (!$stage_ids) {
        return [];
    }

    if ($event_slug === '') {
        return sma_contact_ids_with_tags([], $stage_ids);
    }

    // Candidates: an event's volunteers carry its tag; "_none" applicants have no event tag.
    if ($event_slug === SMA_STAGES_NO_EVENT) {
        $candidates = sma_contact_ids_with_tags([], $stage_ids);
    } else {
        $event_tag = sma_tag_id_by_slug(sma_event_tag_slug($event_slug));
        if (!$event_tag) {
            return [];
        }
        $candidates = sma_contact_ids_with_tags([$event_tag], $stage_ids);
    }

    $ids = [];
    foreach ($candidates as $cid) {
        $profile = sma_volunteer_profile($cid);
        if (isset($profile['events'][$event_slug]) && is_array($profile['events'][$event_slug])) {
            $ids[] = (int) $cid;
        }
    }
    return $ids;
}

/** One dashboard row per volunteer; "stage" is the event's status with a slug, else the latest stage tag. */
function sma_volunteer_rows(array $contact_ids, $event_slug = '')
{
    if (!$contact_ids || !sma_crm_ready()) {
        return [];
    }

    $rows = [];
    try {
        $contacts = \FluentCrm\App\Models\Subscriber::whereIn('id', $contact_ids)->orderBy('id', 'DESC')->get();
    } catch (\Throwable $e) {
        error_log('sma: volunteer list failed (' . get_class($e) . ')');
        return [];
    }

    foreach ($contacts as $contact) {
        $profile = sma_volunteer_profile($contact->id);
        $per     = ($event_slug !== '' && isset($profile['events'][$event_slug]) && is_array($profile['events'][$event_slug]))
            ? $profile['events'][$event_slug]
            : [];
        $role = isset($per['role']) && $per['role'] !== '' ? $per['role'] : (isset($profile['role']) ? $profile['role'] : '');

        $latest = sma_get_stage($contact, 'volunteer');
        $stage  = $event_slug !== ''
            ? sma_volunteer_status_stage(isset($per['status']) ? $per['status'] : '')
            : $latest;

        $entries = [];
        foreach ($profile['events'] as $key => $entry) {
            if (is_array($entry)) {
                $entries[(string) $key] = $entry;
            }
        }

        $rows[] = [
            'contact_id'         => (int) $contact->id,
            'first_name'         => (string) $contact->first_name,
            'last_name'          => (string) $contact->last_name,
            'email'              => (string) $contact->email,
            'phone'              => (string) $contact->phone,
            'role'               => (string) $role,
            'role_label'         => $role !== '' ? sma_volunteer_role_label($role) : '',
            'preferred_location' => isset($profile['preferred_location']) ? (string) $profile['preferred_location'] : '',
            'portfolio_url'      => isset($profile['portfolio_url']) ? (string) $profile['portfolio_url'] : '',
            'portfolio_file'     => isset($profile['portfolio_file']) ? (string) $profile['portfolio_file'] : '',
            'tshirt_size'        => isset($profile['tshirt_size']) ? (string) $profile['tshirt_size'] : '',
            'stage'              => $stage,
            'latest_stage'       => $latest,
            'event_status'       => isset($per['status']) ? (string) $per['status'] : '',
            'applied_at'         => isset($per['applied_at']) ? (string) $per['applied_at'] : (isset($profile['applied_at']) ? (string) $profile['applied_at'] : ''),
            'events'             => sma_contact_events($contact),
            'event_entries'      => $entries,
        ];
    }
    return $rows;
}

/** Volunteers who applied for this event ("_none": no specific date). */
function sma_volunteers_for_event($event_slug)
{
    $event_slug = (string) $event_slug === SMA_STAGES_NO_EVENT ? SMA_STAGES_NO_EVENT : sanitize_title((string) $event_slug);
    if ($event_slug === '') {
        return [];
    }
    return sma_volunteer_rows(sma_volunteer_contact_ids($event_slug), $event_slug);
}

/** Every contact with a volunteer stage, one row each with per-event statuses. */
function sma_volunteers_all()
{
    return sma_volunteer_rows(sma_volunteer_contact_ids());
}

/* ------------------------------------------------ REST (sma/v1): ids, slugs, stages and counts only ------------------------------------------------ */

add_action('rest_api_init', 'sma_volunteers_rest_register');

function sma_volunteers_rest_register()
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
    $text_arg = [
        'required'          => false,
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ];

    // Emails the volunteer at once, so local copy only.
    if (sma_rest_send_routes_enabled()) {
        register_rest_route('sma/v1', '/volunteers/approve', [
            'methods'             => 'POST',
            'permission_callback' => 'sma_rest_can_edit',
            'args'                => ['contact_id' => $contact_arg, 'event' => $event_arg, 'arrival_time' => $text_arg],
            'callback'            => function (WP_REST_Request $request) {
                $extra = [];
                if ((string) $request['arrival_time'] !== '') {
                    $extra['arrival_time'] = (string) $request['arrival_time'];
                }
                $result = sma_approve_volunteer($request['contact_id'], $request['event'], $extra);
                return sma_volunteer_rest_response($result, $request);
            },
        ]);
    }

    register_rest_route('sma/v1', '/volunteers/decline', [
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
            $result = sma_decline_volunteer($request['contact_id'], $request['event'], $request['reason']);
            return sma_volunteer_rest_response($result, $request);
        },
    ]);

    register_rest_route('sma/v1', '/volunteers/stage', [
        'methods'             => ['GET', 'POST'],
        'permission_callback' => 'sma_rest_can_edit',
        // contact_id (GET or POST), or email (POST only).
        'args'                => sma_rest_contact_lookup_args(),
        'callback'            => function (WP_REST_Request $request) {
            $contact = sma_rest_contact_from_request($request);
            if (is_wp_error($contact)) {
                return $contact;
            }
            $profile  = sma_volunteer_profile($contact->id);
            $statuses = [];
            foreach ($profile['events'] as $key => $entry) {
                if (is_array($entry) && isset($entry['status'])) {
                    $statuses[(string) $key] = (string) $entry['status'];
                }
            }
            return [
                'contact_id'     => (int) $contact->id,
                'stage'          => sma_get_stage($contact, 'volunteer'),
                'events'         => sma_contact_events($contact),
                'event_statuses' => $statuses,
                'role'           => isset($profile['role']) ? (string) $profile['role'] : '',
            ];
        },
    ]);

    register_rest_route('sma/v1', '/events/(?P<slug>[a-z0-9-]+)/volunteers/counts', [
        'methods'             => 'GET',
        'permission_callback' => 'sma_rest_can_edit',
        'callback'            => function (WP_REST_Request $request) {
            $slug = sanitize_title($request['slug']);
            if (!sma_event_by_slug($slug)) {
                return new WP_Error('sma_no_event', 'Event not found.', ['status' => 404]);
            }
            $all    = sma_event_stage_counts($slug);
            $counts = isset($all['volunteers']) ? $all['volunteers'] : [];
            return [
                'event'  => $slug,
                'counts' => $counts,
                'total'  => array_sum($counts),
            ];
        },
    ]);
}

function sma_volunteer_rest_response($result, WP_REST_Request $request)
{
    if (is_wp_error($result)) {
        return $result;
    }
    $contact = sma_contact((int) $request['contact_id']);
    return [
        'ok'         => true,
        'contact_id' => (int) $request['contact_id'],
        'event'      => sanitize_title($request['event']),
        'stage'      => $contact ? sma_get_stage($contact, 'volunteer') : '',
    ];
}
