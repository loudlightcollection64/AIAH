<?php
// Admin dashboard: menu, routing and email previews; every email to a person is previewed before it goes.

if (!defined('ABSPATH')) {
    exit;
}

const SMA_DASH_SLUG = 'sma-dashboard';

// Stages an admin may set by hand. Approved and booked go through the approval service and booking.
const SMA_DASH_OVERRIDE_STAGES = ['awaiting-approval', 'attended', 'canceled', 'needs-follow-up'];

// Stages that bulk "Mark attended" moves to attended; Set stage on a row covers anyone else.
const SMA_DASH_BULK_ATTENDED_FROM = ['booked', 'approved', 'needs-follow-up'];

/* ------------------------------------------------------------------------------------ menu */

add_action('admin_menu', 'sma_dash_menu');

function sma_dash_menu()
{
    $hook = add_menu_page(
        'So Many Angels',
        'So Many Angels',
        current_user_can(SMA_FAMILIES_CAP) ? SMA_FAMILIES_CAP : 'manage_options',
        SMA_DASH_SLUG,
        'sma_dash_render',
        'dashicons-heart',
        3
    );
    $GLOBALS['sma_dash_hook'] = $hook;
}

add_action('admin_enqueue_scripts', 'sma_dash_assets');

function sma_dash_assets($hook)
{
    if (empty($GLOBALS['sma_dash_hook']) || $hook !== $GLOBALS['sma_dash_hook']) {
        return;
    }
    sma_enqueue_css();
}

/* ---------------------------------------------------------------------------------- helpers */

/** Dashboard URL with these query args (empty values dropped). */
function sma_dash_url(array $args = [])
{
    $args = array_filter($args, function ($v) {
        return $v !== '' && $v !== null;
    });
    return add_query_arg(array_merge(['page' => SMA_DASH_SLUG], $args), admin_url('admin.php'));
}

function sma_dash_stage_label($stage)
{
    $all = sma_stage_tags();
    if ($stage === '' || $stage === 'none') {
        return 'No stage';
    }
    return isset($all[$stage]) ? $all[$stage] : $stage;
}

/** Stage badge HTML (escaped); with a count it carries data-stage and data-count. */
function sma_dash_badge($stage, $count = null, $label = null)
{
    $slug  = $stage === '' ? 'none' : $stage;
    $label = $label === null ? sma_dash_stage_label($stage) : $label;
    $class = 'sma-badge sma-badge--' . sanitize_html_class($slug);
    $attrs = ' data-stage="' . esc_attr($slug) . '"';
    $text  = $label;
    if ($count !== null) {
        $count  = (int) $count;
        $attrs .= ' data-count="' . esc_attr((string) $count) . '"';
        $text   = $label . ': ' . $count;
        if ($count === 0) {
            $class .= ' sma-badge--zero';
        }
    }
    return '<span class="' . esc_attr($class) . '"' . $attrs . '>' . esc_html($text) . '</span>';
}

/** Print a row action form to admin-post.php with hidden fields, a per-contact nonce, optional controls and a button. */
function sma_dash_action_form($action, $cid, array $fields, array $button, array $opts = [])
{
    $cid    = (int) $cid;
    $hidden = ['action' => $action];
    if (array_key_exists('decision', $fields)) {
        $hidden['decision'] = $fields['decision'];
        unset($fields['decision']);
    }
    $hidden['contact_id'] = (string) $cid;
    $hidden = array_merge($hidden, $fields, ['_wpnonce' => wp_create_nonce($action . '_' . $cid)]);

    $class = trim('sma-admin__form ' . (isset($opts['class']) ? $opts['class'] : ''));
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($class) . '"'
        . ' data-contact="' . esc_attr((string) $cid) . '" data-decision="' . esc_attr(isset($opts['decision']) ? $opts['decision'] : '') . '">';
    foreach ($hidden as $name => $value) {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
    }
    if (isset($opts['controls']) && is_callable($opts['controls'])) {
        call_user_func($opts['controls']);
    }
    echo '<button type="submit" class="' . esc_attr($button[1]) . '">' . esc_html($button[0]) . '</button>';
    echo '</form>';
}

/** Print a family's canvas status and, until delivered, the form recording the next step (sends nothing). */
function sma_dash_canvas_controls($cid, $event_slug, array $canvas, $return_view, $stage_filter = '')
{
    $status = (string) $canvas['status'];
    echo '<span class="sma-admin__canvas" data-canvas="' . esc_attr($status === '' ? 'none' : $status) . '">'
        . esc_html('Canvas: ' . sma_canvas_label($canvas)) . '</span>';
    $next = sma_canvas_next_step($status);
    if ($next === '') {
        return;
    }
    $steps  = sma_canvas_steps();
    static $forms = 0; // keeps select ids unique when siblings' rows repeat the form
    $forms++;
    $row_id = (int) $cid . '-' . $forms;
    sma_dash_action_form(
        'sma_canvas',
        $cid,
        array_merge(sma_dash_return_fields($return_view, $event_slug, $stage_filter), ['step' => $next]),
        ['Mark: ' . $steps[$next], 'button'],
        [
            'decision' => 'canvas',
            'class'    => 'sma-admin__form--canvas',
            'controls' => $next !== 'delivered' ? null : function () use ($row_id) { ?>
                <label class="sma-visually-hidden" for="sma-canvas-how-<?php echo esc_attr($row_id); ?>">How the canvas was delivered</label>
                <select name="how" id="sma-canvas-how-<?php echo esc_attr($row_id); ?>" required>
                    <option value="" disabled selected>How?</option>
                    <?php foreach (sma_canvas_delivery_ways() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php },
        ]
    );
}

/* ---------------------------------------------------------------------------------- render */

function sma_dash_view_path($name)
{
    return SMA_EVENTS_DIR . '/admin/' . $name . '.php';
}

/** Include a view with these variables in scope. */
function sma_dash_include($name, array $vars)
{
    $path = sma_dash_view_path($name);
    if (!file_exists($path)) {
        echo '<p>' . esc_html('Missing view: ' . $name) . '</p>';
        return;
    }
    sma_include_view($path, $vars);
}

/** The notice from a redirect, as [type, message] or null. */
function sma_dash_notice()
{
    $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';
    if ($notice === '') {
        return null;
    }
    $code = isset($_GET['code']) ? sanitize_key(wp_unslash($_GET['code'])) : '';
    $ok = [
        'approved'     => 'Approved. The booking-link email has been sent.',
        'declined'     => 'Declined. A decline email draft is on the family\'s row for you to copy, edit and send; nothing was sent.',
        'declined-booking' => 'Declined, and their booking was cancelled: FluentBooking emailed them its cancellation notice. A decline email draft is on the family\'s row; nothing else was sent.',
        'canceled-booking' => 'Set to canceled, and their booking was cancelled: FluentBooking emailed them its cancellation notice.',
        'stage-saved'  => 'Stage updated.',
        'vol-approved' => 'Volunteer approved.',
        'vol-declined' => 'Volunteer declined.',
        'thanked'      => 'Thank-you email sent.',
        'canvas-saved' => 'Canvas updated. Nothing was sent to the family.',
    ];
    if (isset($ok[$notice])) {
        return ['success', $ok[$notice]];
    }
    if ($notice === 'attended-all') {
        $count = function ($key) {
            return isset($_GET[$key]) ? absint(wp_unslash($_GET[$key])) : 0;
        };
        $message = 'Marked attended: ' . $count('marked') . '. No emails were sent; "Send thank-yous…" previews the thank-yous when you are ready.';
        if ($count('skipped')) {
            $message .= ' Left alone (not booked, approved or needing follow-up for this event): ' . $count('skipped') . '.';
        }
        if ($count('failed')) {
            $message .= ' Could not change: ' . $count('failed') . '. Try those again with Set stage.';
        }
        return [$count('failed') ? 'warning' : 'success', $message];
    }
    if ($notice === 'thanked-all') {
        $count = function ($key) {
            return isset($_GET[$key]) ? absint(wp_unslash($_GET[$key])) : 0;
        };
        $message = 'Thank-yous sent: ' . $count('sent') . '.';
        if ($count('skipped')) {
            $message .= ' Skipped (already thanked or no longer eligible): ' . $count('skipped') . '.';
        }
        if ($count('failed')) {
            $message .= ' Could not send: ' . $count('failed') . '. Try those again later.';
        }
        return [$count('failed') ? 'warning' : 'success', $message];
    }
    $errors = [
        'sma_no_booking_url' => 'This event has no booking link yet, so nothing was sent. The family is now marked needs follow-up.',
        'sma_no_contact'     => 'That contact no longer exists.',
        'sma_no_event'       => 'That event no longer exists.',
        'sma_crm_missing'    => 'FluentCRM is not active.',
        'sma_bad_stage'      => 'That stage cannot be set here.',
        'sma_bad_decision'   => 'Unknown action.',
        'sma_no_event_selected' => 'No event selected, so there is no booking link to send. Decline or set a stage instead.',
        'sma_cannot_approve'    => 'A family in this stage cannot be approved.',
        'sma_cannot_decline'    => 'A family in this stage cannot be declined.',
        'sma_not_registered'    => 'This family has not signed up for that event.',
        'sma_not_confirmed'     => 'Nothing was sent: open the preview, check the email and confirm it there.',
        'sma_already_thanked'   => 'Already thanked for this event, so nothing was sent. The preview offers "Send again" if you meant to.',
        'sma_not_attended'      => 'Only a family marked attended can be thanked.',
        'sma_not_approved'      => 'Only a volunteer approved for this event can be thanked.',
        'sma_has_booking'       => 'This family holds a booking for this event. Open the Decline preview: it shows that the booking will be cancelled.',
        'sma_no_booking'        => 'This family holds no booking for this event, so nothing was cancelled.',
        'sma_booking_cancel_failed' => 'The stage was changed, but their booking could not be cancelled. Please cancel it in FluentBooking.',
        'sma_none_selected'     => 'No families were ticked, so nothing changed.',
        'sma_event_not_started' => 'This event has not happened yet, so families can\'t be marked attended in one go. Use Set stage on a row if you need to.',
        'sma_canvas_step'       => 'That canvas step was already recorded (the page was out of date). Nothing changed.',
        'sma_canvas_how'        => 'Choose whether the canvas was shipped or presented.',
        'sma_canvas_not_attended' => 'Only a family marked attended has a canvas to track.',
    ];
    $message = isset($errors[$code]) ? $errors[$code] : 'That did not work' . ($code !== '' ? ' (' . $code . ')' : '') . '. Nothing else was changed.';
    return ['error', $message];
}

function sma_dash_render()
{
    if (!sma_can_manage_families()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
    }

    $view  = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'events';
    $event = isset($_GET['event']) ? sanitize_title(wp_unslash($_GET['event'])) : '';
    $stage = isset($_GET['stage']) ? sanitize_key(wp_unslash($_GET['stage'])) : '';
    $family_stages = sma_stage_tags('family');
    if ($stage !== '' && $stage !== 'none' && !isset($family_stages[$stage])) {
        $stage = '';
    }

    $tabs = [
        'events'     => 'Events',
        'followups'  => 'Follow-ups',
        'volunteers' => 'Volunteers',
    ];

    echo '<div class="wrap sma sma-admin">';
    echo '<h1 class="sma-admin__title">So Many Angels</h1>';

    $notice = sma_dash_notice();
    if ($notice) {
        echo '<div class="notice notice-' . esc_attr($notice[0]) . ' sma-admin__notice" role="status"><p>' . esc_html($notice[1]) . '</p></div>';
    }

    echo '<nav class="nav-tab-wrapper sma-admin__tabs" aria-label="Dashboard sections">';
    $preview_tab = isset($_GET['return']) && sanitize_key(wp_unslash($_GET['return'])) === 'volunteers' ? 'volunteers' : 'events';
    foreach ($tabs as $key => $label) {
        $active = ($view === $key) || ($view === 'event' && $key === 'events') || ($view === 'preview' && $key === $preview_tab)
            || ($view === 'canvases' && $key === 'followups');
        echo '<a class="nav-tab' . ($active ? ' nav-tab-active' : '') . '" href="' . esc_url(sma_dash_url(['view' => $key])) . '"'
            . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    if (!sma_crm_ready()) {
        echo '<div class="notice notice-warning"><p>' . esc_html('FluentCRM is not active, so there are no families to show.') . '</p></div>';
    }

    switch ($view) {
        case 'event':
            sma_dash_render_event($event, $stage);
            break;
        case 'followups':
            sma_dash_render_followups();
            break;
        case 'canvases':
            sma_dash_include('canvases', ['rows' => sma_crm_ready() ? sma_canvas_rows() : null]);
            break;
        case 'preview':
            sma_dash_render_preview($event, $stage);
            break;
        case 'volunteers':
            sma_dash_include('volunteers', [
                'event_slug' => $event,
                'volunteers' => sma_volunteer_event_rows($event),
                'events'     => sma_events_published(),
            ]);
            break;
        default:
            sma_dash_render_overview();
    }

    echo '</div>';
}

function sma_dash_render_overview()
{
    $rows = [];
    foreach (sma_events_published() as $event) {
        $details = sma_event_details($event);
        $counts  = sma_event_stage_counts($event->post_name);
        $rows[] = [
            'event'      => $event,
            'details'    => $details,
            'capacity'   => (int) get_post_meta($event->ID, 'capacity', true),
            'past'       => sma_event_is_past($event),
            'families'   => $counts['families'],
            'volunteers' => $counts['volunteers'],
            'unstaged'   => sma_unstaged_family_count($event->post_name),
        ];
    }
    $no_event = sma_no_event_family_counts();
    $no_event['volunteers'] = count(sma_volunteer_contact_ids(SMA_STAGES_NO_EVENT));
    sma_dash_include('overview', ['rows' => $rows, 'no_event' => $no_event, 'waiting' => sma_families_waiting_too_long()]);
}

function sma_dash_render_event($event_slug, $stage)
{
    if ($event_slug === SMA_STAGES_NO_EVENT) {
        $counts = sma_no_event_family_counts();
        sma_dash_include('event', [
            'event'    => null,
            'slug'     => SMA_STAGES_NO_EVENT,
            'title'    => 'Individual session / no event',
            'details'  => [],
            'stage'    => $stage,
            'families' => sma_families_for_event(SMA_STAGES_NO_EVENT, $stage),
            'counts'   => $counts['families'],
            'unstaged' => $counts['unstaged'],
            'bulk_attended' => false,
            'waiting'  => sma_families_waiting_too_long(SMA_STAGES_NO_EVENT),
        ]);
        return;
    }

    $event = $event_slug !== '' ? sma_event_by_slug($event_slug) : null;
    if (!$event) {
        echo '<p>' . esc_html('Event not found.') . ' <a href="' . esc_url(sma_dash_url()) . '">' . esc_html('Back to events') . '</a></p>';
        return;
    }
    $details  = sma_event_details($event);
    $families = sma_families_for_event($event->post_name, $stage);
    $counts   = sma_event_stage_counts($event->post_name);
    sma_dash_include('event', [
        'event'    => $event,
        'slug'     => $event->post_name,
        'title'    => trim($details['city'] . ' ' . $details['event_date']),
        'details'  => $details,
        'stage'    => $stage,
        'families' => $families,
        'counts'   => $counts['families'],
        'unstaged' => sma_unstaged_family_count($event->post_name),
        // Bulk "mark attended" is offered on today's and past events only.
        'bulk_attended' => sma_event_attendance_open($event),
        'waiting'       => sma_families_waiting_too_long($event->post_name),
    ]);
}

function sma_dash_render_followups()
{
    $groups = [];
    foreach (sma_events_published() as $event) {
        $families = sma_families_for_event($event->post_name, 'needs-follow-up');
        if ($families) {
            $details  = sma_event_details($event);
            $groups[] = ['slug' => $event->post_name, 'title' => trim($details['city'] . ' ' . $details['event_date']), 'families' => $families];
        }
    }
    $families = sma_families_for_event(SMA_STAGES_NO_EVENT, 'needs-follow-up');
    if ($families) {
        $groups[] = ['slug' => SMA_STAGES_NO_EVENT, 'title' => 'Individual session / no event', 'families' => $families];
    }
    sma_dash_include('followups', ['groups' => $groups]);
}

/** Hidden fields every family row form needs to return to the same view. */
function sma_dash_return_fields($view, $event_slug, $stage = '')
{
    $fields = ['view' => $view, 'event' => $event_slug];
    if ($stage !== '') {
        $fields['stage_filter'] = $stage;
    }
    return $fields;
}

/* -------------------------------------------------------------------------------- previews */

// Approve, Decline and Thank links open a nonce-checked preview; handlers refuse a POST without confirmed=1.
const SMA_DASH_PREVIEW_DECISIONS = ['approve', 'decline', 'cancel', 'thank', 'approve-volunteer', 'thank-volunteer', 'thank-all'];

/** Preview URL for a decision on a contact (thank-all: contact 0), returning to $return_view. */
function sma_dash_preview_url($decision, $contact_id, $event_slug, $return_view = 'event', $stage = '')
{
    $url = sma_dash_url([
        'view'     => 'preview',
        'decision' => $decision,
        'contact'  => $contact_id ? (int) $contact_id : '',
        'event'    => $event_slug,
        'return'   => $return_view,
        'stage'    => $stage,
    ]);
    return add_query_arg('_wpnonce', wp_create_nonce(sma_dash_preview_nonce_action($decision, $contact_id, $event_slug)), $url);
}

function sma_dash_preview_nonce_action($decision, $contact_id, $event_slug)
{
    return $decision === 'thank-all' ? 'sma_preview_event_' . $event_slug : 'sma_preview_' . (int) $contact_id;
}

/** Where Cancel goes: the view the preview was opened from. */
function sma_dash_return_url($return_view, $event_slug, $stage)
{
    if ($return_view === 'followups') {
        return sma_dash_url(['view' => 'followups']);
    }
    if ($return_view === 'volunteers') {
        return sma_dash_url(['view' => 'volunteers', 'event' => $stage]);
    }
    return sma_dash_url(['view' => 'event', 'event' => $event_slug, 'stage' => $stage]);
}

/** Render the preview view from first names, the email address, event details and the rendered email. */
function sma_dash_render_preview($event_slug, $stage)
{
    $decision = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : '';
    $cid      = isset($_GET['contact']) ? absint(wp_unslash($_GET['contact'])) : 0;
    $return   = isset($_GET['return']) ? sanitize_key(wp_unslash($_GET['return'])) : 'event';
    if (!in_array($return, ['event', 'followups', 'volunteers'], true)) {
        $return = 'event';
    }
    // For volunteers the "stage" slot carries the volunteers view's event filter.
    if ($return === 'volunteers') {
        $stage = isset($_GET['stage']) ? sanitize_title(wp_unslash($_GET['stage'])) : '';
    }
    $cancel = sma_dash_return_url($return, $event_slug, $stage);
    $nonce  = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    $preview = null;
    if (!in_array($decision, SMA_DASH_PREVIEW_DECISIONS, true)) {
        $error = 'Unknown action.';
    } elseif (!wp_verify_nonce($nonce, sma_dash_preview_nonce_action($decision, $cid, $event_slug))) {
        $error = 'This preview link has expired. Go back and open it again.';
    } else {
        $preview = $decision === 'thank-all'
            ? sma_dash_preview_thank_all($event_slug)
            : (in_array($decision, ['approve-volunteer', 'thank-volunteer'], true)
                ? sma_dash_preview_volunteer($cid, $event_slug, $decision)
                : sma_dash_preview_family($decision, $cid, $event_slug));
        $error = is_wp_error($preview) ? $preview->get_error_message() : '';
    }

    sma_dash_include('preview', [
        'error'        => $error,
        'preview'      => is_array($preview) ? $preview : null,
        'decision'     => $decision,
        'contact_id'   => $cid,
        'event_slug'   => $event_slug,
        'return_view'  => $return,
        'stage_filter' => $stage,
        'cancel_url'   => $cancel,
    ]);
}

/** A preview's email: rendered subject and body, or the error message in their place. */
function sma_dash_preview_mail($heading, $template, array $vars, $draft = false)
{
    $mail = sma_render_email($template, $vars);
    return [
        'heading'  => $heading,
        'template' => $template,
        'draft'    => $draft,
        'subject'  => is_wp_error($mail) ? '' : $mail['subject'],
        'body'     => is_wp_error($mail) ? 'The email template "' . $template . '" is missing.' : $mail['body'],
        'missing'  => is_wp_error($mail),
    ];
}

/** Preview approving, declining or thanking one family: the services' checks without side effects. */
function sma_dash_preview_family($decision, $cid, $event_slug)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }
    $contact = $cid ? sma_contact($cid) : null;
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'That contact no longer exists.');
    }
    $event = $event_slug === SMA_STAGES_NO_EVENT ? null : sma_event_by_slug($event_slug);
    if (!$event && $event_slug !== SMA_STAGES_NO_EVENT) {
        return new WP_Error('sma_no_event', 'That event no longer exists.');
    }
    $key = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
    if (!sma_signup_entries($contact->id, $key) && !in_array($key, sma_contact_events($contact), true) && sma_event_stage($contact, $key) === '') {
        return new WP_Error('sma_not_registered', 'This family has not signed up for that event.');
    }

    $stage   = sma_event_stage($contact, $key);
    $blocked = '';
    $again   = false;
    $note    = '';
    $mails   = [];
    $live    = []; // FluentBooking bookings a decline or cancel would cancel

    if ($decision === 'approve') {
        $vars = sma_family_email_vars($contact, $event);
        if (!$event) {
            $blocked = 'No event selected, so there is no booking link to send. Decline or set a stage instead.';
        } elseif (sma_stage_is_confirmed($stage)) {
            $blocked = 'Already ' . sma_dash_stage_label($stage) . ' for this event. Nothing would be sent.';
        } elseif (!sma_can_approve($stage, $key)) {
            $blocked = 'A family in this stage (' . sma_dash_stage_label($stage) . ') cannot be approved.';
        } elseif ($vars['booking_url'] === '') {
            $blocked = 'This event has no booking link yet, so there is nothing to send. Open the event and save it to set up booking, then come back.';
        }
        $mails[] = sma_dash_preview_mail('The email they will get', 'family-approved', $vars);
    } elseif ($decision === 'decline' || $decision === 'cancel') {
        $live = $event ? sma_family_live_bookings($contact, $event) : [];
        if ($decision === 'cancel') {
            if (!$event) {
                return new WP_Error('sma_no_event_selected', 'No event selected, so there is no booking to cancel.');
            }
            if (!$live) {
                $blocked = 'This family holds no booking for this event. Use Set stage on their row instead.';
            }
        } elseif ($stage === 'canceled') {
            $blocked = 'Already declined for this event.';
        } elseif (!sma_can_decline($stage)) {
            $blocked = 'A family in this stage (' . sma_dash_stage_label($stage) . ') cannot be declined.';
        }
        if ($decision === 'decline') {
            $mails[] = sma_dash_preview_mail(
                $live ? 'This decline email is a draft for you; it is not sent' : 'This is a draft for you; declining sends nothing',
                'family-declined',
                sma_family_email_vars($contact, $event),
                true
            );
        }
        if ($live) {
            $note    = sma_dash_booking_cancel_note($event, $live);
            $mails[] = sma_dash_booking_cancel_mail($live);
        }
    } else {
        if (!$event) {
            return new WP_Error('sma_no_event_selected', 'No event selected, so there is nothing to thank them for yet.');
        }
        if ($stage !== 'attended') {
            $blocked = 'Only a family marked attended can be thanked. This family is ' . sma_dash_stage_label($stage) . '.';
        }
        $thanked = sma_family_thanked_at($contact->id, $key);
        if ($thanked !== '') {
            $again = true;
            $note  = 'Already thanked on ' . substr($thanked, 0, 16) . '. Confirming sends it again.';
        }
        $mails[] = sma_dash_preview_mail('The email they will get', 'family-thank-you', sma_family_thank_vars($contact, $event));
    }

    foreach ($mails as $mail) {
        if ($mail['missing'] && $blocked === '') {
            $blocked = 'The email template is missing, so nothing can be sent.';
        }
    }

    $titles = [
        'approve' => 'Approve and send the booking link',
        'decline' => 'Decline',
        'cancel'  => 'Set to canceled and cancel their booking',
        'thank'   => 'Send a thank-you',
    ];
    $buttons = [
        'approve' => 'Approve and send',
        'decline' => !empty($live) ? 'Decline and cancel their booking' : 'Decline (sends nothing)',
        'cancel'  => 'Set canceled and cancel their booking',
        'thank'   => $again ? 'Send again' : 'Send thank-you',
    ];
    $first  = trim((string) $contact->first_name);
    return [
        'title'      => $titles[$decision],
        'who'        => [
            'Guardian' => ($first !== '' ? $first : '(no first name)') . ' <' . $contact->email . '>',
            'Children' => sma_join_names(sma_family_child_first_names($contact->id, $key)),
            'Event'    => $event ? sma_events_label($event) : 'No event selected',
        ],
        'groups'     => [],
        'mails'      => $mails,
        'blocked'    => $blocked,
        'again'      => $again,
        'note'       => $note,
        'post_action'=> 'sma_family_decision',
        'nonce'      => wp_create_nonce('sma_family_decision_' . (int) $contact->id),
        'reason'     => $decision === 'decline',
        'button'     => $buttons[$decision],
        // The confirm form then carries cancel_bookings=1, consenting to the cancellation shown.
        'cancels_booking' => (bool) $live,
    ];
}

/** The preview line naming which bookings will be cancelled and that FluentBooking emails the family. */
function sma_dash_booking_cancel_note(WP_Post $event, array $bookings)
{
    $tz    = sma_event_timezone($event);
    $whens = [];
    foreach ($bookings as $booking) {
        try {
            $when = new DateTime((string) $booking->start_time, new DateTimeZone('UTC'));
            $when->setTimezone($tz);
            $whens[] = $when->format('l, F j, g:ia');
        } catch (\Exception $e) {
            $whens[] = 'booking #' . (int) $booking->id;
        }
    }
    return (count($bookings) === 1 ? 'Their booking will be cancelled' : 'Their ' . count($bookings) . ' bookings will be cancelled')
        . ' in FluentBooking (' . implode('; ', $whens) . '), and FluentBooking will email them its cancellation notice (below). '
        . 'Their reminders stop.';
}

/** FluentBooking's "cancelled by organizer" email for the session, as a preview (it fills in the {{...}} codes). */
function sma_dash_booking_cancel_mail(array $bookings)
{
    $mail = [
        'heading'  => 'FluentBooking sends this cancellation email (it fills in the {{…}} parts)',
        'template' => 'fluentbooking-cancelled-by-host',
        'draft'    => false,
        'subject'  => '',
        'body'     => 'FluentBooking\'s cancellation email for this session could not be read.',
        'missing'  => false,
    ];
    try {
        $session = $bookings ? $bookings[0]->calendar_event : null; // Booking.php:119
        $all     = $session ? $session->getNotifications() : [];  // CalendarSlot.php:375
        $email   = isset($all['cancelled_by_host']) && is_array($all['cancelled_by_host']) ? $all['cancelled_by_host'] : [];
        // The same enabled test FluentBooking makes before sending.
        if (!filter_var($email['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $mail['heading'] = 'FluentBooking\'s cancellation email is turned off for this session, so the family gets no email';
            $mail['body']    = '';
        } else {
            $mail['subject'] = (string) ($email['email']['subject'] ?? '');
            $body            = (string) ($email['email']['body'] ?? '');
            $mail['body']    = trim(wp_strip_all_tags(preg_replace('#</(p|h[1-6]|div)>|<br\s*/?>|<hr\s*/?>#i', "\n", $body)));
        }
    } catch (\Throwable $e) {
        error_log('sma: cancellation email preview failed (' . get_class($e) . ')');
    }
    return $mail;
}

/** Preview approving or thanking one volunteer. */
function sma_dash_preview_volunteer($cid, $event_slug, $decision = 'thank-volunteer')
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }
    $contact = $cid ? sma_contact($cid) : null;
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'That contact no longer exists.');
    }
    $event = sma_event_by_slug($event_slug);
    if (!$event) {
        return new WP_Error('sma_no_event', 'That event no longer exists.');
    }
    $profile = sma_volunteer_profile($contact->id);
    $entry   = isset($profile['events'][$event->post_name]) && is_array($profile['events'][$event->post_name]) ? $profile['events'][$event->post_name] : [];
    $first   = trim((string) $contact->first_name);
    $who     = [
        'Volunteer' => ($first !== '' ? $first : '(no first name)') . ' <' . $contact->email . '>',
        'Event'     => sma_events_label($event),
    ];

    // Approve: the confirmation email is shown first, like every other email to a person.
    if ($decision === 'approve-volunteer') {
        $is_volunteer = sma_get_stage($contact, 'volunteer') !== '' || !empty($profile['last_entry_id']);
        $blocked      = !$is_volunteer ? 'This contact has not applied to volunteer.'
            : (($entry['status'] ?? '') === 'approved' ? 'Already approved for this event. Nothing would be sent.' : '');
        $mail = sma_dash_preview_mail('The email they will get', 'volunteer-approved', sma_volunteer_email_vars($contact, $event));
        if ($mail['missing'] && $blocked === '') {
            $blocked = 'The email template is missing, so nothing can be sent.';
        }
        return [
            'title'       => 'Approve a volunteer and send their confirmation',
            'who'         => $who,
            'groups'      => [],
            'mails'       => [$mail],
            'blocked'     => $blocked,
            'again'       => false,
            'note'        => '',
            'post_action' => 'sma_volunteer_decision',
            'nonce'       => wp_create_nonce('sma_volunteer_decision_' . (int) $contact->id),
            'reason'      => false,
            'button'      => 'Approve and send',
        ];
    }

    $blocked = ($entry['status'] ?? '') === 'approved' ? '' : 'Only a volunteer approved for this event can be thanked.';
    $thanked = isset($entry['thanked_at']) ? (string) $entry['thanked_at'] : '';
    $mail    = sma_dash_preview_mail('The email they will get', 'volunteer-thank-you', sma_volunteer_email_vars($contact, $event));
    if ($mail['missing'] && $blocked === '') {
        $blocked = 'The email template is missing, so nothing can be sent.';
    }

    return [
        'title'       => 'Thank a volunteer',
        'who'         => $who,
        'groups'      => [],
        'mails'       => [$mail],
        'blocked'     => $blocked,
        'again'       => $thanked !== '',
        'note'        => $thanked !== '' ? 'Already thanked on ' . substr($thanked, 0, 16) . '. Confirming sends it again.' : '',
        'post_action' => 'sma_volunteer_decision',
        'nonce'       => wp_create_nonce('sma_volunteer_decision_' . (int) $contact->id),
        'reason'      => false,
        'button'      => $thanked !== '' ? 'Send again' : 'Send thank-you',
    ];
}

/** Preview "Send thank-yous" for an event: who gets one, and each email as the first recipient sees it. */
function sma_dash_preview_thank_all($event_slug)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }
    $event = sma_event_by_slug($event_slug);
    if (!$event) {
        return new WP_Error('sma_no_event', 'That event no longer exists.');
    }
    $who   = sma_thank_recipients($event->post_name);
    $mails = [];
    foreach ($who['families'] as $cid => $name) {
        $contact = sma_contact($cid);
        if ($contact) {
            $mails[] = sma_dash_preview_mail('Families get this (shown as ' . ($name !== '' ? $name : 'the first family') . ' will see it; each family gets their own names)', 'family-thank-you', sma_family_thank_vars($contact, $event));
            break;
        }
    }
    foreach ($who['volunteers'] as $cid => $name) {
        $contact = sma_contact($cid);
        if ($contact) {
            $mails[] = sma_dash_preview_mail('Volunteers get this (shown as ' . ($name !== '' ? $name : 'the first volunteer') . ' will see it)', 'volunteer-thank-you', sma_volunteer_email_vars($contact, $event));
            break;
        }
    }

    $blocked = (!$who['families'] && !$who['volunteers']) ? 'Everyone who attended or volunteered has been thanked already. Nothing to send.' : '';
    foreach ($mails as $mail) {
        if ($mail['missing'] && $blocked === '') {
            $blocked = 'An email template is missing, so nothing can be sent.';
        }
    }
    $names = function (array $list) {
        return array_map(function ($n) {
            return $n !== '' ? $n : '(no first name)';
        }, array_values($list));
    };

    return [
        'title'       => 'Send thank-yous for ' . sma_events_label($event),
        'who'         => ['Event' => sma_events_label($event)],
        'groups'      => [
            ['label' => 'Families (attended)', 'count' => count($who['families']), 'names' => $names($who['families'])],
            ['label' => 'Volunteers (approved)', 'count' => count($who['volunteers']), 'names' => $names($who['volunteers'])],
        ],
        'mails'       => $mails,
        'blocked'     => $blocked,
        'again'       => false,
        'note'        => $who['thanked'] ? 'Already thanked, and left out: ' . $who['thanked'] . '.' : '',
        'post_action' => 'sma_thank_all',
        'nonce'       => wp_create_nonce('sma_thank_all_' . $event->post_name),
        'reason'      => false,
        'button'      => 'Send ' . (count($who['families']) + count($who['volunteers'])) . ' thank-you emails',
    ];
}

