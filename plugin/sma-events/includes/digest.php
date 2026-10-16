<?php
/** Daily digest email to the site admin: counts and volunteer first names only, sent only when something needs attention. */

if (!defined('ABSPATH')) {
    exit;
}

const SMA_DIGEST_HOOK          = 'sma_digest_daily';
const SMA_DIGEST_LAST_OPTION   = 'sma_digest_last_sent';   // site time, Y-m-d H:i:s
const SMA_DIGEST_UPCOMING_DAYS = 21;
const SMA_DIGEST_TEMPLATE      = 'admin-digest';
const SMA_DIGEST_MAX_NAMES     = 8;

/* ------------------------------------------------------------------------------- schedule */

add_action('init', 'sma_digest_schedule', 30);
add_action(SMA_DIGEST_HOOK, 'sma_digest_cron');

/** Next due time (Unix): today at digest_time in the site time zone, or tomorrow if passed. */
function sma_digest_next_time($now = null)
{
    $now  = $now === null ? time() : (int) $now;
    $zone = wp_timezone();
    $hhmm = (string) sma_setting('digest_time');
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
    $at    = new DateTimeImmutable($today . ' ' . $hhmm, $zone);
    if ($at->getTimestamp() <= $now) {
        $at = new DateTimeImmutable($at->modify('+1 day')->format('Y-m-d') . ' ' . $hhmm, $zone);
    }
    return $at->getTimestamp();
}

/** Keep one daily cron event at the set time while on (rescheduled after DST shifts), none while off. */
function sma_digest_schedule()
{
    $next = wp_next_scheduled(SMA_DIGEST_HOOK);
    if (!sma_setting('digest_enabled')) {
        if ($next) {
            wp_clear_scheduled_hook(SMA_DIGEST_HOOK);
        }
        return;
    }
    if ($next && wp_date('H:i', $next) === (string) sma_setting('digest_time')) {
        return;
    }
    if ($next) {
        wp_clear_scheduled_hook(SMA_DIGEST_HOOK);
    }
    wp_schedule_event(sma_digest_next_time(), 'daily', SMA_DIGEST_HOOK);
}

/** Called on plugin deactivation (sma-events.php). */
function sma_digest_unschedule()
{
    wp_clear_scheduled_hook(SMA_DIGEST_HOOK);
}

/** Cron callback: send at most once a day, and only when the digest has something to say. */
function sma_digest_cron()
{
    if (!sma_setting('digest_enabled')) {
        return;
    }
    $last = (string) get_option(SMA_DIGEST_LAST_OPTION, '');
    if ($last !== '' && substr($last, 0, 10) === current_time('Y-m-d')) {
        return; // already sent today
    }
    try {
        $digest = sma_digest_build();
    } catch (\Throwable $e) {
        error_log('sma: digest build failed (' . get_class($e) . ')');
        return;
    }
    if (!$digest['worth_sending']) {
        return;
    }
    $sent = sma_digest_send($digest);
    if ($sent === true) {
        update_option(SMA_DIGEST_LAST_OPTION, current_time('mysql'), false);
    }
}

/** Where the digest goes: the "digest_recipient" setting, else the site's admin email. */
function sma_digest_recipient()
{
    $to = (string) sma_setting('digest_recipient');
    return $to !== '' ? $to : (string) get_option('admin_email');
}

/* ---------------------------------------------------------------------------------- build */

/** "Pittsburgh, Sat Oct 24" for an event. */
function sma_digest_event_label(WP_Post $event)
{
    $city = trim((string) get_post_meta($event->ID, 'city', true));
    $date = trim((string) get_post_meta($event->ID, 'event_date', true));
    $when = '';
    if ($date !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $when = $d ? $d->format('D M j') : $date;
    }
    $name = $city !== '' ? $city : get_the_title($event);
    return trim($name . ($when !== '' ? ', ' . $when : ''));
}

/** Families awaiting approval for an event key, and how many have waited longer than $days: [waiting, overdue]. */
function sma_digest_waiting_for($key, array $contact_ids, $days)
{
    $out    = ['waiting' => 0, 'overdue' => 0];
    $cutoff = time() - max(1, (int) $days) * DAY_IN_SECONDS;
    $zone   = wp_timezone();
    foreach (sma_family_stages_for($contact_ids, $key) as $cid => $stage) {
        if ($stage !== 'awaiting-approval') {
            continue;
        }
        $out['waiting']++;
        $map   = sma_stage_map($cid);
        $since = isset($map[$key]['changed_at']) ? (string) $map[$key]['changed_at'] : '';
        if ($since === '') {
            continue;
        }
        try {
            $ts = (new DateTimeImmutable($since, $zone))->getTimestamp();
        } catch (\Exception $e) {
            continue;
        }
        if ($ts < $cutoff) {
            $out['overdue']++;
        }
    }
    // Prefer the shared approval-nudge helper when it is available.
    if (function_exists('sma_families_waiting_too_long')) {
        $shared = sma_families_waiting_too_long($key);
        if (is_int($shared)) {
            $out['overdue'] = $shared;
        } elseif (is_array($shared)) {
            $out['overdue'] = count($shared);
        }
    }
    return $out;
}

/** Booked families whose session should still have a pending reminder but has none; null without Action Scheduler. */
function sma_digest_booked_without_reminders(WP_Post $event, array $contact_ids)
{
    $days = (array) sma_setting('reminder_days');
    if (!$days) {
        return 0;
    }
    // A booking closer than the last reminder day has no reminder left to send; that's normal.
    $after = time() + (int) min($days) * DAY_IN_SECONDS + HOUR_IN_SECONDS;
    $tz    = sma_event_timezone($event);
    $slug  = $event->post_name;
    $n     = 0;
    foreach (sma_family_stages_for($contact_ids, $slug) as $cid => $stage) {
        if ($stage !== 'booked') {
            continue;
        }
        $booking = sma_booking_for($cid, $slug);
        if (!is_array($booking) || ($booking['status'] ?? '') !== 'scheduled' || empty($booking['start_time'])) {
            continue;
        }
        $start = strtotime((string) $booking['start_time'] . ' UTC');
        if (!$start || $start <= $after) {
            continue;
        }
        $labels = sma_booking_reminder_labels(isset($booking['id']) ? $booking['id'] : 0, $tz);
        if ($labels === null) {
            return null;
        }
        if (!$labels) {
            $n++;
        }
    }
    return $n;
}

/** Everything the digest says, as data (counts, event labels, volunteer first names; no family names). */
function sma_digest_build()
{
    $nudge = (int) sma_setting('approval_nudge_days');
    $out = [
        'waiting'            => [],
        'waiting_total'      => 0,
        'overdue_total'      => 0,
        'nudge_days'         => $nudge,
        'upcoming'           => [],
        'attention'          => [],
        'volunteers_new'     => [],
        'volunteers_waiting' => 0,
        'since'              => '',
        'worth_sending'      => false,
    ];
    if (!sma_crm_ready()) {
        $out['attention'][] = 'FluentCRM is not active, so the site can\'t see families or volunteers.';
        $out['worth_sending'] = true;
        return $out;
    }

    $open      = sma_events_open();
    $horizon   = wp_date('Y-m-d', time() + SMA_DIGEST_UPCOMING_DAYS * DAY_IN_SECONDS);
    $seen      = [];

    foreach ($open as $event) {
        $seen[$event->ID] = true;
        $slug   = $event->post_name;
        $label  = sma_digest_event_label($event);
        $tag_id = sma_tag_id_by_slug(sma_event_tag_slug($slug));
        $ids    = $tag_id ? sma_contact_ids_with_tags([$tag_id]) : [];

        $w = sma_digest_waiting_for($slug, $ids, $nudge);
        if ($w['waiting'] > 0) {
            $out['waiting'][] = [$label, $w['waiting'], $w['overdue']];
            $out['waiting_total'] += $w['waiting'];
            $out['overdue_total'] += $w['overdue'];
        }

        $date = (string) get_post_meta($event->ID, 'event_date', true);
        if ($date !== '' && $date <= $horizon) {
            $volunteers = 0;
            foreach (sma_volunteers_for_event($slug) as $v) {
                if (($v['stage'] ?? '') === 'volunteer-approved') {
                    $volunteers++;
                }
            }
            $start = trim((string) get_post_meta($event->ID, 'start_time', true));
            $end   = trim((string) get_post_meta($event->ID, 'end_time', true));
            $start = $start !== '' ? sma_events_time_label($start) : '';
            $end   = $end !== '' ? sma_events_time_label($end) : '';
            $out['upcoming'][] = [
                $label,
                $start !== '' ? $start . ($end !== '' ? ' to ' . $end : '') : '',
                count(sma_event_upcoming_bookings($event)),
                (int) get_post_meta($event->ID, 'capacity', true),
                $volunteers,
            ];
        }

        $problem = sma_provision_warning($event);
        if ($problem !== '') {
            $out['attention'][] = $label . ': ' . $problem;
        }
        $warnings = sma_event_booking_warnings($event);
        foreach ($warnings as $warning) {
            $out['attention'][] = $label . ': ' . $warning;
        }
        // The booking warnings above already cover reminders paused by an unpublish or a move.
        $quiet = ($ids && !$warnings) ? sma_digest_booked_without_reminders($event, $ids) : 0;
        if ($quiet) {
            $out['attention'][] = $label . ': ' . ($quiet === 1 ? '1 booked family has' : $quiet . ' booked families have')
                . ' no reminder emails waiting to go out. Rescheduling a booking in FluentBooking starts its reminders again; or remind them yourself.';
        }
    }

    // Unpublished events can still hold bookings (sma_booking_alerts_live()).
    foreach (sma_events_with_booking_alerts() as $event) {
        if (isset($seen[$event->ID]) || $event->post_status === 'trash') {
            continue;
        }
        foreach (sma_event_booking_warnings($event) as $warning) {
            $out['attention'][] = sma_digest_event_label($event) . ': ' . $warning;
        }
    }

    // Families who chose no specific date.
    $w = sma_digest_waiting_for(SMA_STAGES_NO_EVENT, sma_no_event_contact_ids(), $nudge);
    if ($w['waiting'] > 0) {
        $out['waiting'][] = ['No specific date (individual sessions)', $w['waiting'], $w['overdue']];
        $out['waiting_total'] += $w['waiting'];
        $out['overdue_total'] += $w['overdue'];
    }

    // Volunteers: applications since the last digest (or the last day), and all still undecided.
    $since = (string) get_option(SMA_DIGEST_LAST_OPTION, '');
    if ($since === '') {
        $since = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    }
    $out['since'] = $since;
    foreach (sma_volunteers_all() as $v) {
        $entries = isset($v['event_entries']) && is_array($v['event_entries']) ? $v['event_entries'] : [];
        $applied = false;
        $new     = false;
        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') !== 'applied') {
                continue;
            }
            $applied = true;
            if ((string) ($entry['applied_at'] ?? '') > $since) {
                $new = true;
            }
        }
        if ($applied) {
            $out['volunteers_waiting']++;
        }
        if ($new) {
            $out['volunteers_new'][] = trim((string) $v['first_name']) !== '' ? trim((string) $v['first_name']) : 'someone';
        }
    }

    $out['worth_sending'] = $out['waiting_total'] > 0 || $out['attention'] || $out['volunteers_new'];
    return $out;
}

/* ----------------------------------------------------------------------------------- text */

function sma_digest_plural($n, $one, $many)
{
    return (int) $n === 1 ? '1 ' . $one : (int) $n . ' ' . $many;
}

/** The email's subject line: counts only. */
function sma_digest_subject(array $d)
{
    $parts = [];
    if ($d['waiting_total']) {
        $parts[] = sma_digest_plural($d['waiting_total'], 'family', 'families') . ' to approve';
    }
    if ($d['attention']) {
        $parts[] = sma_digest_plural(count($d['attention']), 'thing', 'things') . ' to check';
    }
    if ($d['volunteers_new']) {
        $parts[] = sma_digest_plural(count($d['volunteers_new']), 'new volunteer', 'new volunteers');
    }
    if (!$parts && $d['upcoming']) {
        $parts[] = sma_digest_plural(count($d['upcoming']), 'event', 'events') . ' coming up';
    }
    return 'So Many Angels today: ' . ($parts ? implode(', ', $parts) : 'nothing needs you');
}

/** The digest's sections as plain text. */
function sma_digest_blocks(array $d)
{
    $blocks = [];

    if ($d['waiting']) {
        $lines = ['WAITING FOR YOUR APPROVAL: ' . sma_digest_plural($d['waiting_total'], 'family', 'families')];
        foreach ($d['waiting'] as $row) {
            list($label, $waiting, $overdue) = $row;
            $lines[] = '- ' . $label . ': ' . $waiting
                . ($overdue ? ' (' . $overdue . ' waiting more than ' . sma_digest_plural($d['nudge_days'], 'day', 'days') . ')' : '');
        }
        $blocks[] = implode("\n", $lines);
    }

    if ($d['upcoming']) {
        $lines = ['COMING UP IN THE NEXT ' . SMA_DIGEST_UPCOMING_DAYS . ' DAYS'];
        foreach ($d['upcoming'] as $row) {
            list($label, $time, $booked, $capacity, $volunteers) = $row;
            $lines[] = '- ' . $label . ($time !== '' ? ', ' . $time : '') . ': '
                . $booked . ' of ' . $capacity . ' sessions booked, '
                . sma_digest_plural($volunteers, 'volunteer', 'volunteers') . ' approved';
        }
        $blocks[] = implode("\n", $lines);
    }

    if ($d['attention']) {
        $lines = ['NEEDS A LOOK'];
        foreach ($d['attention'] as $line) {
            $lines[] = '- ' . $line;
        }
        $blocks[] = implode("\n", $lines);
    }

    if ($d['volunteers_new'] || $d['volunteers_waiting']) {
        $lines = ['VOLUNTEERS'];
        if ($d['volunteers_new']) {
            $names = array_slice($d['volunteers_new'], 0, SMA_DIGEST_MAX_NAMES);
            $more  = count($d['volunteers_new']) - count($names);
            $lines[] = '- New since the last digest: ' . count($d['volunteers_new']) . ' (' . implode(', ', $names)
                . ($more > 0 ? ' and ' . $more . ' more' : '') . ')';
        }
        if ($d['volunteers_waiting']) {
            $lines[] = '- Waiting for your decision: ' . $d['volunteers_waiting'];
        }
        $blocks[] = implode("\n", $lines);
    }

    if (!$blocks) {
        $blocks[] = 'Nothing needs you today.';
    }
    return implode("\n\n", $blocks);
}

/* ----------------------------------------------------------------------------------- send */

/** Send a built digest via sma_send_email(); the multi-line sections go in through sma_email_vars for this send only. */
function sma_digest_send(array $digest, $to = null)
{
    $to     = $to === null ? sma_digest_recipient() : (string) $to;
    $blocks = sma_digest_blocks($digest);
    $keep   = function ($vars, $template) use ($blocks) {
        if ($template === SMA_DIGEST_TEMPLATE) {
            $vars['blocks'] = $blocks;
        }
        return $vars;
    };
    add_filter('sma_email_vars', $keep, 10, 2);
    try {
        $sent = sma_send_email($to, SMA_DIGEST_TEMPLATE, [
            'subject'       => sma_digest_subject($digest),
            'blocks'        => 'x', // replaced by $keep
            'dashboard_url' => sma_dash_url(),
            'settings_url'  => add_query_arg(['page' => SMA_SETTINGS_SLUG], admin_url('admin.php')),
        ]);
    } finally {
        remove_filter('sma_email_vars', $keep, 10);
    }
    return $sent;
}

/* ------------------------------------------------------------------ "Send a test digest now" */

add_action('admin_post_sma_digest_test', 'sma_digest_test');

function sma_digest_test()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
    }
    check_admin_referer('sma_digest_test', '_sma_digest_nonce');

    $notice = 'digest_sent';
    try {
        $sent = sma_digest_send(sma_digest_build());
        if ($sent !== true) {
            $notice = 'digest_failed';
        }
    } catch (\Throwable $e) {
        error_log('sma: test digest failed (' . get_class($e) . ')');
        $notice = 'digest_failed';
    }

    wp_safe_redirect(add_query_arg(
        ['page' => SMA_SETTINGS_SLUG, 'sma_notice' => $notice],
        admin_url('admin.php')
    ) . '#sma-digest');
    exit;
}

/** Next scheduled digest as a site-time label, or '' when off. */
function sma_digest_next_label()
{
    $next = wp_next_scheduled(SMA_DIGEST_HOOK);
    return $next ? wp_date('D M j, g:i a', $next) : '';
}
