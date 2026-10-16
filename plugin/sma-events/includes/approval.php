<?php
// Approve, decline and thank families, and follow FluentBooking bookings; callers check capabilities.

if (!defined('ABSPATH')) {
    exit;
}

/** [contact, event] for a decision that needs a real event, or WP_Error. */
function sma_decision_targets($contact_id, $event_slug)
{
    if ((string) $event_slug === SMA_STAGES_NO_EVENT) {
        return new WP_Error('sma_no_event', 'Event not found.', ['status' => 404]);
    }
    $targets = sma_family_targets($contact_id, $event_slug);
    return is_wp_error($targets) ? $targets : [$targets[0], $targets[1]];
}

/** Shared checks for a family decision: [contact, event or null for "_none", key] or WP_Error. */
function sma_family_targets($contact_id, $event_slug)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.', ['status' => 503]);
    }
    $contact = sma_contact((int) $contact_id);
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'Contact not found.', ['status' => 404]);
    }
    $event_slug = (string) $event_slug;
    $event      = sma_choice_event($event_slug);
    if (!$event && $event_slug !== SMA_STAGES_NO_EVENT) {
        // An unknown slug is an error here, not silently "no event".
        return new WP_Error('sma_no_event', 'Event not found.', ['status' => 404]);
    }
    $key    = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
    $signed = (bool) sma_signup_entries($contact->id, $key);

    if (!$event) {
        if (!$signed && sma_event_stage($contact, $key) === '') {
            return new WP_Error('sma_not_registered', 'This contact has no sign-up without an event.', ['status' => 409]);
        }
        return [$contact, null, $key];
    }

    // Only a family registered for the event (event tag or sign-up entry) can be decided on.
    $tagged = in_array($key, sma_contact_events($contact), true);
    if (!$tagged && !$signed) {
        return new WP_Error('sma_not_registered', 'This contact has not signed up for this event.', ['status' => 409]);
    }

    // A sign-up whose event tag went missing gets it back.
    if (!$tagged) {
        $tag_id = sma_event_ensure_tag($event);
        if ($tag_id) {
            $contact->attachTags([$tag_id]);
        }
    }

    return [$contact, $event, $key];
}

/** "Ava", "Ava and Ben", "Ava, Ben and Cal". */
function sma_join_names(array $names)
{
    $names = array_values($names);
    if (count($names) <= 1) {
        return $names ? (string) $names[0] : '';
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/** Email variables for a family and event: first names (every child signed up) and event details only. */
function sma_family_email_vars($contact, $event)
{
    $key   = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
    $names = sma_family_child_first_names($contact->id, $key);
    if (!$names && !sma_signup_entries($contact->id, $key)) {
        // A contact made before entry lists existed: its newest entry.
        $legacy = sma_entry_child_first_name((int) sma_contact_meta_get($contact->id, 'sma_last_entry_id', 0));
        $names  = $legacy !== '' ? [$legacy] : [];
    }
    $child = sma_join_names($names);
    $first = trim((string) $contact->first_name);

    $details = $event ? sma_event_details($event) : ['city' => '', 'date_label' => '', 'booking_url' => ''];
    return array_merge($details, [
        'guardian_first_name' => $first !== '' ? $first : 'there',
        'child_first_name'    => $child !== '' ? $child : 'your child',
        'child_possessive'    => count($names) > 1 ? "each child's" : sma_possessive($child),
    ]);
}

/* ------------------------------------------------ decline drafts ------------------------------------------------ */

// A private option, "<contact id>|<key>" => {subject, body, reason, at, by}: drafts name the child, so they stay out of the CRM and REST.
const SMA_DECLINE_DRAFTS_OPTION = 'sma_decline_drafts';

function sma_decline_drafts_all()
{
    $all = get_option(SMA_DECLINE_DRAFTS_OPTION, []);
    return is_array($all) ? $all : [];
}

/** Save a decline draft (subject, body, reason) for a contact and event, or delete it with null. */
function sma_decline_draft_save($contact_id, $key, $draft)
{
    $all = sma_decline_drafts_all();
    $id  = (int) $contact_id . '|' . sma_stage_key($key);
    if ($draft === null) {
        if (!isset($all[$id])) {
            return;
        }
        unset($all[$id]);
    } else {
        $all[$id] = [
            'subject' => (string) ($draft['subject'] ?? ''),
            'body'    => (string) ($draft['body'] ?? ''),
            'reason'  => (string) ($draft['reason'] ?? ''),
            'at'      => current_time('mysql'),
            'by'      => (int) get_current_user_id(),
        ];
    }
    update_option(SMA_DECLINE_DRAFTS_OPTION, $all, false);
}

/** Approve a family for an event and email the booking link: true or WP_Error. */
function sma_approve_family($contact_id, $event_slug)
{
    $targets = sma_family_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event, $key) = $targets;
    if (!$event) {
        return new WP_Error('sma_no_event_selected', 'No event selected, so there is no booking link to send.', ['status' => 409]);
    }
    $slug = $event->post_name;

    // Already approved or booked for this event (stage and last decision): no second booking-link email.
    $stage = sma_event_stage($contact, $slug);
    $last  = sma_last_decision($contact->id, $slug);
    if (sma_stage_is_confirmed($stage) && sma_stage_is_confirmed($last)) {
        return true;
    }
    if (!sma_stage_is_confirmed($stage) && !sma_can_approve($stage, $key)) {
        return new WP_Error('sma_cannot_approve', 'A family in this stage cannot be approved.', ['status' => 409]);
    }

    $vars = sma_family_email_vars($contact, $event);

    if ($vars['booking_url'] === '') {
        sma_set_stage($contact, 'needs-follow-up', $slug);
        sma_record_decision($contact->id, $slug, 'needs-follow-up', 'Approval blocked: the event has no booking link.');
        sma_add_contact_note($contact->id, 'Approval blocked', 'Tried to approve for ' . $slug . ', but the event has no booking link yet. Nothing was sent.');
        return new WP_Error('sma_no_booking_url', 'This event has no booking link yet, so nothing was sent. The family is marked needs-follow-up.', ['status' => 409]);
    }

    $staged = sma_set_stage($contact, 'approved', $slug);
    if (is_wp_error($staged)) {
        return $staged;
    }

    $sent = sma_send_email($contact->email, 'family-approved', $vars);
    if (is_wp_error($sent)) {
        sma_set_stage($contact, 'needs-follow-up', $slug);
        sma_record_decision($contact->id, $slug, 'needs-follow-up', 'Approved, but the booking-link email failed.');
        sma_add_contact_note($contact->id, 'Booking-link email failed', 'Approved for ' . $slug . ', but the booking-link email could not be sent.');
        return $sent;
    }

    sma_record_decision($contact->id, $slug, 'approved');
    sma_decline_draft_save($contact->id, $slug, null);
    sma_add_contact_note($contact->id, 'Approved', 'Approved for ' . $slug . ' by ' . sma_current_user_label() . '. Booking-link email sent.');

    return true;
}

/** Decline a family for an event (drafting, never sending, the email); a held booking is cancelled only with $cancel_bookings. */
function sma_decline_family($contact_id, $event_slug, $reason = '', $cancel_bookings = false)
{
    $targets = sma_family_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event, $key) = $targets;
    $reason = sanitize_textarea_field((string) $reason);

    $stage = sma_event_stage($contact, $key);
    if ($stage === 'canceled') {
        return true;
    }
    if (!sma_can_decline($stage)) {
        return new WP_Error('sma_cannot_decline', 'A family in this stage cannot be declined.', ['status' => 409]);
    }

    $live = $event ? sma_family_live_bookings($contact, $event) : [];
    if ($live && !$cancel_bookings) {
        return new WP_Error('sma_has_booking', 'This family holds a booking for this event. Decline them from the dashboard, where the preview shows that the booking will be cancelled.', ['status' => 409]);
    }

    // Rendered before anything changes, so a missing template changes nothing.
    $draft = sma_render_email('family-declined', sma_family_email_vars($contact, $event));
    if (is_wp_error($draft)) {
        return $draft;
    }

    $staged = sma_set_stage($contact, 'canceled', $key);
    if (is_wp_error($staged)) {
        return $staged;
    }

    // A draft for the organiser, never sent, in plugin storage only.
    sma_decline_draft_save($contact->id, $key, [
        'subject' => (string) $draft['subject'],
        'body'    => (string) $draft['body'],
        'reason'  => $reason,
    ]);

    sma_record_decision($contact->id, $key, 'declined');
    sma_add_contact_note(
        $contact->id,
        'Declined',
        'Declined for ' . $key . ' by ' . sma_current_user_label() . '. A decline email draft is on the So Many Angels dashboard; '
            . ($live ? 'their booking is cancelled (FluentBooking emails its cancellation notice); nothing else was sent.' : 'nothing was sent.')
    );

    // After the stage change, so the cancellation hook (sma_on_booking_cancelled) leaves the stage alone.
    return $live ? sma_cancel_family_bookings($contact, $event, $live) : true;
}

/** Set a family to canceled and cancel their bookings for the event (only after the dashboard's preview). */
function sma_cancel_family($contact_id, $event_slug)
{
    $targets = sma_family_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event, $key) = $targets;
    if (!$event) {
        return new WP_Error('sma_no_event_selected', 'No event selected, so there is no booking to cancel.', ['status' => 409]);
    }
    $live = sma_family_live_bookings($contact, $event);
    if (!$live) {
        return new WP_Error('sma_no_booking', 'This family holds no booking for this event.', ['status' => 409]);
    }

    $staged = sma_set_stage($contact, 'canceled', $key);
    if (is_wp_error($staged)) {
        return $staged;
    }
    sma_record_decision($contact->id, $key, 'canceled', 'Set by hand on the dashboard; booking cancelled.');
    sma_add_contact_note(
        $contact->id,
        'Stage set to Canceled',
        'Set by hand for ' . $key . ' by ' . sma_current_user_label() . ' on the dashboard. Their booking is cancelled (FluentBooking emails its cancellation notice).'
    );
    return sma_cancel_family_bookings($contact, $event, $live);
}

/** Scheduled or pending bookings this contact holds on the event's own session. */
function sma_family_live_bookings($contact, WP_Post $event)
{
    $slot_id = (int) get_post_meta($event->ID, 'booking_session_id', true);
    $email   = is_object($contact) ? trim((string) $contact->email) : '';
    if (!$slot_id || $email === '' || !class_exists('\FluentBooking\App\Models\Booking')) {
        return [];
    }
    $out = [];
    try {
        $rows = \FluentBooking\App\Models\Booking::where('event_id', $slot_id)
            ->where('email', $email)
            ->whereIn('status', ['scheduled', 'pending'])
            ->get();
        foreach ($rows as $row) {
            $out[] = $row;
        }
    } catch (\Throwable $e) {
        error_log('sma: booking lookup failed for event ' . (int) $event->ID . ' (' . get_class($e) . ')');
    }
    return $out;
}

/** Cancel bookings as FluentBooking's host cancel does (it emails the family), then remove their queued reminders. */
function sma_cancel_family_bookings($contact, WP_Post $event, array $bookings)
{
    $failed = [];
    foreach ($bookings as $booking) {
        $id = (int) $booking->id;
        try {
            $result = $booking->cancelMeeting('', 'host', get_current_user_id());
        } catch (\Throwable $e) {
            error_log('sma: cancelling booking #' . $id . ' failed (' . get_class($e) . ')');
            $result = new WP_Error('sma_booking_cancel_failed', 'Cancel failed.');
        }
        if (is_wp_error($result)) {
            $failed[] = $id;
            continue;
        }
        sma_booking_pause_reminders($id); // provisioning.php
        sma_booking_save($contact->id, $event->post_name, sma_booking_record($booking, 'cancelled', $event->post_name));
    }
    if ($failed) {
        sma_add_contact_note($contact->id, 'Booking not cancelled', 'Booking #' . implode(', #', $failed) . ' for ' . $event->post_name . ' could not be cancelled. Please cancel it in FluentBooking.');
        return new WP_Error('sma_booking_cancel_failed', 'The stage was changed, but their booking could not be cancelled. Please cancel it in FluentBooking.', ['status' => 500]);
    }
    return true;
}

/* ------------------------------------------------ thank-you after the event ------------------------------------------------ */

// Contact meta: event slug => {at, by}.
const SMA_THANKED_META = 'sma_thanked';

/** When this family was thanked for this event, or ''. */
function sma_family_thanked_at($contact_id, $event_slug)
{
    $thanked = sma_contact_meta_get((int) $contact_id, SMA_THANKED_META, []);
    return (is_array($thanked) && isset($thanked[$event_slug]['at'])) ? (string) $thanked[$event_slug]['at'] : '';
}

/** Thank-you variables: the family's plus the donation link, and the story line only with story consent. */
function sma_family_thank_vars($contact, WP_Post $event)
{
    $vars = sma_family_email_vars($contact, $event);

    $story = false;
    foreach (sma_child_entries(sma_signup_entries($contact->id, $event->post_name)) as $entry) {
        if (isset($entry['story_ok']) && $entry['story_ok'] === 'yes') {
            $story = true;
            break;
        }
    }
    $vars['story_possessive'] = $story ? $vars['child_possessive'] : '';
    $vars['givebutter_url']   = esc_url_raw(sma_givebutter_url());
    return $vars;
}

/** Send the thank-you email to an attended family, once per event unless $again. */
function sma_thank_family($contact_id, $event_slug, $again = false)
{
    $targets = sma_family_targets($contact_id, $event_slug);
    if (is_wp_error($targets)) {
        return $targets;
    }
    list($contact, $event) = $targets;
    if (!$event) {
        return new WP_Error('sma_no_event_selected', 'No event selected, so there is nothing to thank them for yet.', ['status' => 409]);
    }
    $slug = $event->post_name;

    if (sma_event_stage($contact, $slug) !== 'attended') {
        return new WP_Error('sma_not_attended', 'Only a family marked attended can be thanked.', ['status' => 409]);
    }
    if (!$again && sma_family_thanked_at($contact->id, $slug) !== '') {
        return new WP_Error('sma_already_thanked', 'This family has already been thanked for this event.', ['status' => 409]);
    }

    $sent = sma_send_email($contact->email, 'family-thank-you', sma_family_thank_vars($contact, $event));
    if (is_wp_error($sent)) {
        return $sent;
    }

    $thanked = sma_contact_meta_get($contact->id, SMA_THANKED_META, []);
    if (!is_array($thanked)) {
        $thanked = [];
    }
    $thanked[$slug] = ['at' => current_time('mysql'), 'by' => (int) get_current_user_id()];
    sma_contact_meta_set($contact->id, SMA_THANKED_META, $thanked);
    sma_add_contact_note($contact->id, 'Thank-you sent', 'Thank-you email for ' . $slug . ' sent by ' . sma_current_user_label() . '.');

    return true;
}

/** The last recorded decision ("approved", "booked", ...) for this contact and event, or ''. */
function sma_last_decision($contact_id, $event_slug)
{
    $decisions = sma_contact_meta_get((int) $contact_id, 'sma_decisions', []);
    return (is_array($decisions) && isset($decisions[$event_slug]['action']))
        ? (string) $decisions[$event_slug]['action']
        : '';
}

function sma_current_user_label()
{
    $user = wp_get_current_user();
    return ($user && $user->ID) ? $user->display_name : 'system';
}

/* ------------------------------------------------ FluentBooking hooks ------------------------------------------------ */

add_action('fluent_booking/after_booking_scheduled', 'sma_on_booking_scheduled', 20, 1);
add_action('fluent_booking/booking_schedule_cancelled', 'sma_on_booking_cancelled', 20, 1);
add_action('fluent_booking/after_booking_rescheduled', 'sma_on_booking_rescheduled', 20, 1);

/** The sma_event whose own session this booking is on (never matched by calendar, which events share), or ''. */
function sma_event_for_booking($booking)
{
    $session = isset($booking->event_id) ? (int) $booking->event_id : 0;
    if (!$session) {
        return '';
    }
    $found = get_posts([
        'post_type'      => SMA_EVENTS_POST_TYPE,
        'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_key'       => 'booking_session_id',
        'meta_value'     => $session,
    ]);
    return $found ? $found[0]->post_name : '';
}

/** A booking record: ids and UTC times only. */
function sma_booking_record($booking, $status, $event_slug = null)
{
    return [
        'id'          => isset($booking->id) ? (int) $booking->id : 0,
        'start_time'  => isset($booking->start_time) ? (string) $booking->start_time : '',
        'end_time'    => isset($booking->end_time) ? (string) $booking->end_time : '',
        'timezone'    => 'UTC',
        'calendar_id' => isset($booking->calendar_id) ? (int) $booking->calendar_id : 0,
        'session_id'  => isset($booking->event_id) ? (int) $booking->event_id : 0,
        'event'       => $event_slug !== null ? (string) $event_slug : sma_event_for_booking($booking),
        'status'      => $status,
        'updated_at'  => current_time('mysql'),
    ];
}

/** Store a booking record under its event in "sma_bookings". */
function sma_booking_save($contact_id, $event_slug, array $record)
{
    $bookings = sma_contact_meta_get((int) $contact_id, 'sma_bookings', []);
    if (!is_array($bookings)) {
        $bookings = [];
    }
    $bookings[sanitize_title((string) $event_slug)] = $record;
    sma_contact_meta_set((int) $contact_id, 'sma_bookings', $bookings);
}

/** The booking record for one event ("sma_bookings", else the older single "sma_booking"), or null. */
function sma_booking_for($contact_id, $event_slug)
{
    $event_slug = (string) $event_slug;
    $bookings   = sma_contact_meta_get((int) $contact_id, 'sma_bookings', []);
    if (is_array($bookings) && isset($bookings[$event_slug]) && is_array($bookings[$event_slug])) {
        return $bookings[$event_slug];
    }
    $legacy = sma_contact_meta_get((int) $contact_id, 'sma_booking', null);
    if (is_array($legacy)) {
        $linked = isset($legacy['event']) ? (string) $legacy['event'] : '';
        if ($linked === '' || $linked === $event_slug) {
            return $legacy;
        }
    }
    return null;
}

function sma_booking_contact($booking)
{
    if (!is_object($booking) || empty($booking->email) || !sma_crm_ready()) {
        return null;
    }
    return sma_contact_by_email((string) $booking->email);
}

/** ['contact', 'slug', 'tagged'] for a booking on an sma_event's session by a known contact, else null. */
function sma_booking_target($booking)
{
    $slug = sma_event_for_booking($booking);
    if ($slug === '') {
        return null;
    }
    $contact = sma_booking_contact($booking);
    if (!$contact) {
        if (is_object($booking) && isset($booking->id)) {
            error_log('sma: booking #' . (int) $booking->id . ' on event ' . $slug . ' has no matching contact');
        }
        return null;
    }
    return [
        'contact' => $contact,
        'slug'    => $slug,
        'tagged'  => in_array($slug, sma_contact_events($contact), true),
    ];
}

function sma_on_booking_scheduled($booking)
{
    try {
        $target = sma_booking_target($booking);
        if (!$target) {
            return;
        }
        $contact = $target['contact'];
        $slug    = $target['slug'];
        $record  = sma_booking_record($booking, 'scheduled', $slug);

        // Booked for an event this contact never signed up for: leave a note, change nothing.
        if (!$target['tagged']) {
            sma_add_contact_note($contact->id, 'Booking without a sign-up', 'Booking #' . $record['id'] . ' is for ' . $slug . ', but this contact has not signed up for that event. Please check it.');
            return;
        }

        // Approved for this event by stage and, when recorded, by last decision.
        $stage = sma_event_stage($contact, $slug);
        $last  = sma_last_decision($contact->id, $slug);
        if (sma_stage_is_confirmed($stage) && ($last === '' || sma_stage_is_confirmed($last))) {
            sma_booking_save($contact->id, $slug, $record);
            sma_set_stage($contact, 'booked', $slug);
            sma_record_decision($contact->id, $slug, 'booked', 'Booking #' . $record['id']);
            return;
        }

        // Signed up but not approved (the booking link was passed on, or found): flag it.
        sma_booking_save($contact->id, $slug, $record);
        sma_set_stage($contact, 'needs-follow-up', $slug);
        sma_record_decision($contact->id, $slug, 'needs-follow-up', 'Booking #' . $record['id'] . ' made before approval.');
        sma_add_contact_note($contact->id, 'Booked before approval', 'Booking #' . $record['id'] . ' for ' . $slug . ' was made while the family was "' . ($stage !== '' ? $stage : 'no stage') . '", not approved. Needs follow-up.');
    } catch (\Throwable $e) {
        error_log('sma: booking hook failed (' . get_class($e) . ')');
    }
}

function sma_on_booking_cancelled($booking)
{
    try {
        $target = sma_booking_target($booking);
        if (!$target || !$target['tagged']) {
            return;
        }
        $contact = $target['contact'];
        // Only a family booked for this event moves; an approved family that never booked is left alone.
        if (sma_event_stage($contact, $target['slug']) !== 'booked') {
            return;
        }
        $record = sma_booking_record($booking, 'cancelled', $target['slug']);
        sma_booking_save($contact->id, $target['slug'], $record);
        sma_set_stage($contact, 'needs-follow-up', $target['slug']);
        sma_record_decision($contact->id, $record['event'], 'needs-follow-up', 'Booking #' . $record['id'] . ' was cancelled.');
        sma_add_contact_note($contact->id, 'Booking cancelled', 'Booking #' . $record['id'] . ' was cancelled. Needs follow-up.');
    } catch (\Throwable $e) {
        error_log('sma: booking cancel hook failed (' . get_class($e) . ')');
    }
}

function sma_on_booking_rescheduled($booking)
{
    try {
        $target = sma_booking_target($booking);
        if (!$target || !$target['tagged'] || sma_event_stage($target['contact'], $target['slug']) !== 'booked') {
            return;
        }
        sma_booking_save($target['contact']->id, $target['slug'], sma_booking_record($booking, 'scheduled', $target['slug']));
    } catch (\Throwable $e) {
        error_log('sma: booking reschedule hook failed (' . get_class($e) . ')');
    }
}
