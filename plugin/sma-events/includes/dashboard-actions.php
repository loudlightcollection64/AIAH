<?php
// The dashboard's admin-post handlers: each checks capability and nonce, calls one service, and redirects back with a notice.

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_sma_family_decision', 'sma_dash_handle_family_decision');
add_action('admin_post_sma_thank_all', 'sma_dash_handle_thank_all');
add_action('admin_post_sma_family_stage', 'sma_dash_handle_family_stage');
add_action('admin_post_sma_volunteer_decision', 'sma_dash_handle_volunteer_decision');
add_action('admin_post_sma_bulk_attended', 'sma_dash_handle_bulk_attended');
add_action('admin_post_sma_canvas', 'sma_dash_handle_canvas');

/** Sanitised POST fields shared by the row actions. */
function sma_dash_post_common()
{
    return [
        'contact_id'   => isset($_POST['contact_id']) ? absint(wp_unslash($_POST['contact_id'])) : 0,
        'event'        => isset($_POST['event']) ? sanitize_title(wp_unslash($_POST['event'])) : '',
        'view'         => isset($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : 'event',
        'stage_filter' => isset($_POST['stage_filter']) ? sanitize_key(wp_unslash($_POST['stage_filter'])) : '',
        'decision'     => isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '',
        'reason'       => isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '',
        // Set only by a preview's confirm form (admin/preview.php).
        'confirmed'    => isset($_POST['confirmed']) && (string) wp_unslash($_POST['confirmed']) === '1',
        'again'        => isset($_POST['again']) && (string) wp_unslash($_POST['again']) === '1',
        // Set only by a decline preview that showed the booking being cancelled (admin/preview.php).
        'cancel_bookings' => isset($_POST['cancel_bookings']) && (string) wp_unslash($_POST['cancel_bookings']) === '1',
    ];
}

/** Whether the family holds a live FluentBooking booking for this event. */
function sma_dash_family_holds_booking($contact_id, $event_slug)
{
    if ((string) $event_slug === SMA_STAGES_NO_EVENT || !sma_crm_ready()) {
        return false;
    }
    $event   = sma_event_by_slug($event_slug);
    $contact = $event ? sma_contact((int) $contact_id) : null;
    return $contact ? (bool) sma_family_live_bookings($contact, $event) : false;
}

function sma_dash_require_cap()
{
    if (!sma_can_manage_families()) {
        wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
    }
}

/** Redirect back to the view the form came from with a notice (and $extra args on success); exits. */
function sma_dash_redirect_back(array $in, $result, $ok_notice, array $extra = [])
{
    $views = ['event', 'followups', 'volunteers', 'events', 'canvases'];
    $view  = in_array($in['view'], $views, true) ? $in['view'] : 'event';
    $args  = ['view' => $view];
    if ($view === 'event' || ($view === 'volunteers' && !empty($in['event_filter']))) {
        $args['event'] = $view === 'event' ? $in['event'] : $in['event_filter'];
    }
    if ($view === 'event' && $in['stage_filter'] !== '') {
        $args['stage'] = $in['stage_filter'];
    }
    if (is_wp_error($result)) {
        $args['notice'] = 'error';
        $args['code']   = sanitize_key($result->get_error_code());
    } else {
        $args['notice'] = $ok_notice;
        $args = array_merge($args, $extra);
    }
    wp_safe_redirect(sma_dash_url($args));
    exit;
}

/** Approve, decline, cancel or thank a family; only a preview's confirm form (confirmed=1) acts. */
function sma_dash_handle_family_decision()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    check_admin_referer('sma_family_decision_' . $in['contact_id']);

    if (!$in['confirmed']) {
        sma_dash_redirect_back($in, new WP_Error('sma_not_confirmed', 'Not confirmed.'), '');
    }
    if (!$in['contact_id'] || $in['event'] === '') {
        sma_dash_redirect_back($in, new WP_Error('sma_no_contact', 'Missing contact or event.'), '');
    }

    // One service for every group; approval refuses "_none" itself (sma_no_event_selected).
    if ($in['decision'] === 'approve') {
        sma_dash_redirect_back($in, sma_approve_family($in['contact_id'], $in['event']), 'approved');
    }
    if ($in['decision'] === 'decline') {
        // cancel_bookings=1 comes only from a preview that showed the cancellation.
        $held = $in['cancel_bookings'] && sma_dash_family_holds_booking($in['contact_id'], $in['event']);
        sma_dash_redirect_back($in, sma_decline_family($in['contact_id'], $in['event'], $in['reason'], $in['cancel_bookings']), $held ? 'declined-booking' : 'declined');
    }
    if ($in['decision'] === 'cancel') {
        sma_dash_redirect_back($in, sma_cancel_family($in['contact_id'], $in['event']), 'canceled-booking');
    }
    if ($in['decision'] === 'thank') {
        sma_dash_redirect_back($in, sma_thank_family($in['contact_id'], $in['event'], $in['again']), 'thanked');
    }
    sma_dash_redirect_back($in, new WP_Error('sma_bad_decision', 'Unknown decision.'), '');
}

/** "Send thank-yous": thank every attended family and approved volunteer not yet thanked, then report counts. */
function sma_dash_handle_thank_all()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    $in['view'] = 'event';
    $in['stage_filter'] = '';
    check_admin_referer('sma_thank_all_' . $in['event']);

    if (!$in['confirmed']) {
        sma_dash_redirect_back($in, new WP_Error('sma_not_confirmed', 'Not confirmed.'), '');
    }
    if ($in['event'] === '' || !sma_event_by_slug($in['event'])) {
        sma_dash_redirect_back($in, new WP_Error('sma_no_event', 'Event not found.'), '');
    }

    $who    = sma_thank_recipients($in['event']);
    $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    $tally  = function ($result, $kind, $cid) use (&$counts) {
        if ($result === true) {
            $counts['sent']++;
        } elseif (is_wp_error($result) && in_array($result->get_error_code(), ['sma_already_thanked', 'sma_not_attended', 'sma_not_approved'], true)) {
            $counts['skipped']++;
        } else {
            $counts['failed']++;
            error_log('sma: thank-you to ' . $kind . ' contact ' . (int) $cid . ' failed: ' . (is_wp_error($result) ? $result->get_error_code() : 'unknown'));
        }
    };
    foreach (array_keys($who['families']) as $cid) {
        $tally(sma_thank_family($cid, $in['event']), 'family', $cid);
    }
    foreach (array_keys($who['volunteers']) as $cid) {
        $tally(sma_thank_volunteer($cid, $in['event']), 'volunteer', $cid);
    }

    sma_dash_redirect_back($in, true, 'thanked-all', $counts);
}

/** Set a stage by hand (stage map, tag, decision record, contact note); sends nothing. */
function sma_dash_apply_stage($contact_id, $stage, $event_slug)
{
    $result = sma_set_stage($contact_id, $stage, $event_slug);
    if (!is_wp_error($result)) {
        sma_record_decision($contact_id, $event_slug, $stage, 'Set by hand on the dashboard.');
        sma_add_contact_note(
            $contact_id,
            'Stage set to ' . sma_dash_stage_label($stage),
            'Set by hand for ' . $event_slug . ' by ' . sma_current_user_label() . ' on the dashboard.'
        );
    }
    return $result;
}

function sma_dash_handle_family_stage()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    check_admin_referer('sma_family_stage_' . $in['contact_id']);

    $stage = isset($_POST['new_stage']) ? sanitize_key(wp_unslash($_POST['new_stage'])) : '';
    if (!in_array($stage, SMA_DASH_OVERRIDE_STAGES, true)) {
        sma_dash_redirect_back($in, new WP_Error('sma_bad_stage', 'Stage not allowed.'), '');
    }
    if (!$in['contact_id'] || $in['event'] === '' || ($in['event'] !== SMA_STAGES_NO_EVENT && !sma_event_by_slug($in['event']))) {
        sma_dash_redirect_back($in, new WP_Error('sma_no_event', 'Missing contact or event.'), '');
    }

    // Canceling a family with a booking emails them, so it goes through a preview first.
    if ($stage === 'canceled' && sma_dash_family_holds_booking($in['contact_id'], $in['event'])) {
        $return = $in['view'] === 'followups' ? 'followups' : 'event';
        wp_safe_redirect(sma_dash_preview_url('cancel', $in['contact_id'], $in['event'], $return, $in['stage_filter']));
        exit;
    }

    $result = sma_dash_apply_stage($in['contact_id'], $stage, $in['event']);
    if (!is_wp_error($result)) {
        // Attended at a real event: offer the thank-you preview; the stage stays attended either way.
        if ($stage === 'attended' && $in['event'] !== SMA_STAGES_NO_EVENT) {
            $return = $in['view'] === 'followups' ? 'followups' : 'event';
            wp_safe_redirect(add_query_arg('notice', 'stage-saved', sma_dash_preview_url('thank', $in['contact_id'], $in['event'], $return, $in['stage_filter'])));
            exit;
        }
    }
    sma_dash_redirect_back($in, $result, 'stage-saved');
}

/** Mark ticked families attended on a today's or past event, as Set stage does, without the thank-you preview. */
function sma_dash_handle_bulk_attended()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    $in['view'] = 'event';
    check_admin_referer('sma_bulk_attended_' . $in['event']);

    $event = $in['event'] !== '' ? sma_event_by_slug($in['event']) : null;
    if (!$event) {
        sma_dash_redirect_back($in, new WP_Error('sma_no_event', 'Event not found.'), '');
    }
    if (!sma_event_attendance_open($event)) {
        sma_dash_redirect_back($in, new WP_Error('sma_event_not_started', 'The event has not happened yet.'), '');
    }
    $ids = isset($_POST['contact_ids']) ? (array) wp_unslash($_POST['contact_ids']) : [];
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if (!$ids) {
        sma_dash_redirect_back($in, new WP_Error('sma_none_selected', 'No families ticked.'), '');
    }

    $stages = sma_family_stages_for($ids, $event->post_name);
    $counts = ['marked' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($ids as $cid) {
        $stage = isset($stages[$cid]) ? $stages[$cid] : '';
        if (!in_array($stage, SMA_DASH_BULK_ATTENDED_FROM, true)) {
            $counts['skipped']++;
            continue;
        }
        $result = sma_dash_apply_stage($cid, 'attended', $event->post_name);
        if (is_wp_error($result)) {
            $counts['failed']++;
            error_log('sma: mark attended for contact ' . (int) $cid . ' failed: ' . $result->get_error_code());
        } else {
            $counts['marked']++;
        }
    }
    sma_dash_redirect_back($in, true, 'attended-all', $counts);
}

/** Move an attended family's canvas one step on; sends nothing. */
function sma_dash_handle_canvas()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    check_admin_referer('sma_canvas_' . $in['contact_id']);

    $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
    $how  = isset($_POST['how']) ? sanitize_key(wp_unslash($_POST['how'])) : '';
    if (!$in['contact_id'] || $in['event'] === '') {
        sma_dash_redirect_back($in, new WP_Error('sma_no_contact', 'Missing contact or event.'), '');
    }
    sma_dash_redirect_back($in, sma_canvas_advance($in['contact_id'], $in['event'], $step, $how), 'canvas-saved');
}

function sma_dash_handle_volunteer_decision()
{
    sma_dash_require_cap();
    $in = sma_dash_post_common();
    $in['event_filter'] = isset($_POST['event_filter']) ? sanitize_title(wp_unslash($_POST['event_filter'])) : '';
    $in['view'] = 'volunteers';
    check_admin_referer('sma_volunteer_decision_' . $in['contact_id']);

    // Approve emails the volunteer, so only from its preview's confirm form.
    if ($in['decision'] === 'approve') {
        $result = $in['confirmed']
            ? sma_approve_volunteer($in['contact_id'], $in['event'], [])
            : new WP_Error('sma_not_confirmed', 'Not confirmed.');
        sma_dash_redirect_back($in, $result, 'vol-approved');
    }
    if ($in['decision'] === 'decline') {
        sma_dash_redirect_back($in, sma_decline_volunteer($in['contact_id'], $in['event'], $in['reason']), 'vol-declined');
    }
    // The thank-you only from its preview's confirm form (admin/preview.php).
    if ($in['decision'] === 'thank') {
        $result = $in['confirmed']
            ? sma_thank_volunteer($in['contact_id'], $in['event'], $in['again'])
            : new WP_Error('sma_not_confirmed', 'Not confirmed.');
        sma_dash_redirect_back($in, $result, 'thanked');
    }
    sma_dash_redirect_back($in, new WP_Error('sma_bad_decision', 'Unknown decision.'), '');
}
