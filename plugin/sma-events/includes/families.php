<?php
// Family and volunteer query layer (one row per child, allow-listed fields only); callers check capabilities.

if (!defined('ABSPATH')) {
    exit;
}

/** Published events: upcoming soonest first, then past most recent first. */
function sma_events_published()
{
    $events = get_posts([
        'post_type'      => SMA_EVENTS_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
    $upcoming = [];
    $past = [];
    foreach ($events as $event) {
        // Past in the event's own time zone.
        if (sma_event_is_past($event)) {
            $past[] = $event;
        } else {
            $upcoming[] = $event;
        }
    }
    $by_date = function ($a, $b) {
        return strcmp((string) get_post_meta($a->ID, 'event_date', true), (string) get_post_meta($b->ID, 'event_date', true));
    };
    usort($upcoming, $by_date);
    usort($past, function ($a, $b) use ($by_date) {
        return $by_date($b, $a);
    });
    return array_merge($upcoming, $past);
}

/* ---------------------------------------------------------------------------- child fields */

/** Child fields the dashboard and export may show (an allow-list). */
function sma_child_fields()
{
    return array_keys(sma_dash_child_field_names());
}

/** "yes" when a consent field holds anything (a ticked box), else "no". */
function sma_consent_value(array $data, array $candidates)
{
    foreach ($candidates as $name) {
        if (!isset($data[$name])) {
            continue;
        }
        $value = $data[$name];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    return 'yes';
                }
            }
        } elseif (is_scalar($value) && trim((string) $value) !== '') {
            return 'yes';
        }
    }
    return 'no';
}

/** Allow-listed child fields, consents and created_at for these entries: entry id => fields. */
function sma_child_entries(array $entry_ids)
{
    $out = [];
    foreach (sma_entries_read($entry_ids) as $id => $entry) {
        $data   = $entry['data'];
        $picked = [];
        foreach (sma_dash_child_field_names() as $key => $candidates) {
            $picked[$key] = sanitize_text_field(sma_field($data, $candidates));
        }
        foreach (sma_dash_consent_field_names() as $key => $candidates) {
            $picked[$key] = sma_consent_value($data, $candidates);
        }
        unset($data);
        $picked['registered_at'] = $entry['created_at'];
        $out[(int) $id] = $picked;
    }
    return $out;
}

/* ---------------------------------------------------------------------------------- labels */

/** A scheduled booking's start as "Y-m-d H:i" in the event's zone, or ''. */
function sma_booking_start_label($booking, $event_slug, DateTimeZone $tz)
{
    if (!is_array($booking) || empty($booking['start_time']) || ($booking['status'] ?? '') !== 'scheduled') {
        return '';
    }
    $linked = (string) ($booking['event'] ?? '');
    if ($linked !== '' && $linked !== $event_slug) {
        return '';
    }
    try {
        $when = new DateTime((string) $booking['start_time'], new DateTimeZone('UTC'));
        $when->setTimezone($tz);
        return $when->format('Y-m-d H:i');
    } catch (\Exception $e) {
        return '';
    }
}

/** Dates ("Nov 28") of a booking's pending family reminders, earliest first; null without Action Scheduler. */
function sma_booking_reminder_labels($booking_id, DateTimeZone $tz)
{
    $booking_id = (int) $booking_id;
    if ($booking_id <= 0 || !function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
        return null;
    }
    $times = [];
    foreach ([$booking_id, (string) $booking_id] as $id) {
        $ids = as_get_scheduled_actions([
            'hook'     => 'fluent_booking/booking_schedule_reminder',
            'args'     => [$id, 'guest'],
            'group'    => 'fluent-booking',
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 20,
        ], 'ids');
        foreach ((array) $ids as $action_id) {
            $date = ActionScheduler::store()->get_date((int) $action_id);
            if ($date) {
                $times[(int) $action_id] = $date->getTimestamp();
            }
        }
    }
    sort($times);
    return array_map(function ($ts) use ($tz) {
        return wp_date('M j', $ts, $tz);
    }, array_values($times));
}

/** "approved by Jane, 2026-10-14 10:02" from a decision record. */
function sma_decision_label($decision)
{
    if (!is_array($decision) || empty($decision['action'])) {
        return '';
    }
    $label = (string) $decision['action'];
    if (!empty($decision['by_name'])) {
        $label .= ' by ' . $decision['by_name'];
    }
    if (!empty($decision['at'])) {
        $label .= ', ' . substr((string) $decision['at'], 0, 16);
    }
    return $label;
}

/* -------------------------------------------------------------------------------- families */

/** Contact ids of families with no event: a family stage but no event tag, or a sign-up or stage under "_none". */
function sma_no_event_contact_ids()
{
    if (!sma_crm_ready()) {
        return [];
    }
    global $wpdb;
    $meta = $wpdb->prefix . 'fc_subscriber_meta';

    $staged = sma_contact_ids_with_tags([], array_values(sma_tag_ids_by_slugs(array_keys(sma_stage_tags('family')))));

    $event_tag_ids = sma_event_tag_ids();
    $with_event    = $event_tag_ids ? sma_contact_ids_with_tags([], $event_tag_ids) : [];
    $ids = array_diff($staged, $with_event);

    // fc_subscriber_meta: subscriber_id, key, value (FluentCRM 3.1.8).
    $signed = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT subscriber_id FROM {$meta}
          WHERE (`key` = %s AND (value LIKE %s OR value LIKE %s)) OR (`key` = %s AND value LIKE %s)",
        SMA_SIGNUPS_META,
        '%' . $wpdb->esc_like('"' . SMA_STAGES_NO_EVENT . '"') . '%',
        '%' . $wpdb->esc_like('"' . SMA_EVENTS_KEEP_PREFIX) . '%',
        SMA_STAGES_META,
        '%' . $wpdb->esc_like('"' . SMA_STAGES_NO_EVENT . '"') . '%'
    ));

    return array_values(array_unique(array_merge(array_map('intval', $ids), array_map('intval', (array) $signed))));
}

/** Family rows for an event (or "_none"), one per child, filtered by stage ('none' or '' for all); null without FluentCRM. */
function sma_families_for_event($event_slug, $stage_filter = '')
{
    if (!sma_crm_ready()) {
        return null;
    }
    $event = null;
    if ((string) $event_slug === SMA_STAGES_NO_EVENT) {
        $ids = sma_no_event_contact_ids();
    } else {
        $event = sma_event_by_slug($event_slug);
        if (!$event) {
            return [];
        }
        $tag_id = sma_tag_id_by_slug(sma_event_tag_slug($event->post_name));
        if (!$tag_id) {
            return [];
        }
        $ids = sma_contact_ids_with_tags([$tag_id]);
    }
    if (!$ids) {
        return [];
    }

    global $wpdb;
    $subs     = $wpdb->prefix . 'fc_subscribers';
    $id_ph    = implode(',', array_fill(0, count($ids), '%d'));
    $contacts = $wpdb->get_results($wpdb->prepare(
        "SELECT id, first_name, last_name, email, phone, created_at
           FROM {$subs}
          WHERE id IN ({$id_ph})
          ORDER BY last_name ASC, first_name ASC, id ASC",
        $ids
    ));
    if (!$contacts) {
        return [];
    }

    $stage_tags = sma_stage_tags_for_contacts(array_map(function ($c) {
        return (int) $c->id;
    }, $contacts));
    $drafts  = sma_decline_drafts_all();
    $tz      = $event ? sma_event_timezone($event) : wp_timezone();
    $key     = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
    $nudge   = sma_approval_nudge_applies($event);

    $rows = [];
    $entry_ids = [];
    foreach ($contacts as $c) {
        $cid  = (int) $c->id;
        $tags = isset($stage_tags[$cid]) ? $stage_tags[$cid] : [];
        $map  = sma_stage_map($cid);
        // This event's stage: the map entry, else the stage tag.
        $stage = sma_stage_from_map($map, $key, isset($tags['family']) ? $tags['family'] : '');
        if ($stage === '' && isset($tags['volunteer'])) {
            continue; // a volunteer for this event, not a family
        }
        if ($stage_filter !== '' && $stage_filter !== ($stage === '' ? 'none' : $stage)) {
            continue;
        }

        $entries   = sma_signup_entries($cid, $key);
        $records   = sma_entry_records($cid);
        $decisions = sma_contact_meta_get($cid, 'sma_decisions', []);
        $decision  = (is_array($decisions) && isset($decisions[$key])) ? $decisions[$key] : null;
        $booking   = $event ? sma_booking_for($cid, $event->post_name) : null;
        $draft_id  = $cid . '|' . $key;
        $draft     = ($stage === 'canceled' && isset($drafts[$draft_id]) && is_array($drafts[$draft_id])) ? $drafts[$draft_id] : null;

        $family = [
            'contact_id'      => $cid,
            'event_slug'      => $key,
            'no_event'        => !$event,
            'first_name'      => (string) $c->first_name,
            'last_name'       => (string) $c->last_name,
            'email'           => (string) $c->email,
            'phone'           => (string) $c->phone,
            'stage'           => $stage,
            'children'        => count($entries),
            'booking_start'   => sma_booking_start_label($booking, $key, $tz),
            // Pending reminders for a booked family's upcoming session; null when not applicable.
            'reminders'       => ($stage === 'booked' && sma_booking_start_label($booking, $key, $tz) !== ''
                                  && strtotime((string) $booking['start_time'] . ' UTC') > time())
                ? sma_booking_reminder_labels(isset($booking['id']) ? $booking['id'] : 0, $tz) : null,
            'decision'        => is_array($decision) ? $decision : null,
            'decision_label'  => sma_decision_label($decision),
            'draft'           => $draft,
            'thanked_at'      => ($event && $stage === 'attended') ? sma_family_thanked_at($cid, $key) : '',
            'contact_created' => (string) $c->created_at,
            // When the family entered its current stage for this event ('' when unknown).
            'stage_since'     => isset($map[$key]['changed_at']) ? (string) $map[$key]['changed_at'] : '',
            // Canvas progress for an attended family (canvas.php); null otherwise.
            'canvas'          => $stage === 'attended' ? sma_canvas_for($cid, $key) : null,
        ];
        // How long an awaiting family has waited, and whether that is past the nudge setting.
        $since = $stage === 'awaiting-approval' ? sma_waiting_since($map, $key, (string) $c->created_at) : '';
        $family['waiting_days']     = $since !== '' ? sma_waiting_days($since) : null;
        $family['waiting_too_long'] = $since !== '' && $nudge && sma_waiting_too_long($since);
        foreach ($entries ? $entries : [0] as $entry_id) {
            $record = ($entry_id && isset($records[(string) $entry_id]) && is_array($records[(string) $entry_id])) ? $records[(string) $entry_id] : [];
            $rows[] = array_merge($family, [
                'entry_id' => (int) $entry_id,
                'flags'    => isset($record['flags']) && is_array($record['flags']) ? array_values($record['flags']) : [],
                'choice'   => isset($record['choice']) ? (string) $record['choice'] : '',
            ]);
            if ($entry_id) {
                $entry_ids[] = (int) $entry_id;
            }
        }
    }

    $entries = sma_child_entries($entry_ids);
    $consent = array_keys(sma_dash_consent_field_names());
    foreach ($rows as &$row) {
        $entry = ($row['entry_id'] && isset($entries[$row['entry_id']])) ? $entries[$row['entry_id']] : [];
        foreach (array_merge(sma_child_fields(), $consent) as $field) {
            $row[$field] = isset($entry[$field]) ? $entry[$field] : '';
        }
        $row['registered_at'] = !empty($entry['registered_at']) ? $entry['registered_at'] : $row['contact_created'];
    }
    unset($row);

    return $rows;
}

/* ---------------------------------------------------------------------------------- counts */

/** Each contact's family stage for one event: contact id => stage ('' when none). */
function sma_family_stages_for(array $contact_ids, $event_slug)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $contact_ids))));
    if (!$ids || !sma_crm_ready()) {
        return [];
    }

    $tags = sma_stage_tags_for_contacts($ids);
    $key  = sma_stage_key($event_slug);
    $out  = [];
    foreach ($ids as $cid) {
        $out[$cid] = sma_stage_from_map(sma_stage_map($cid), $key, isset($tags[$cid]['family']) ? $tags[$cid]['family'] : '');
    }
    return $out;
}

/** Family rows (children) a contact contributes to an event; at least one. */
function sma_family_row_count($contact_id, $key)
{
    return max(1, count(sma_signup_entries($contact_id, $key)));
}

/** Children per family stage for this event, plus contacts, tagged contacts and per-event volunteer counts. */
function sma_event_stage_counts($event_slug)
{
    $families   = array_fill_keys(array_keys(sma_stage_tags('family')), 0);
    $volunteers = array_fill_keys(array_keys(sma_stage_tags('volunteer')), 0);
    $out = [
        'event'      => $event_slug,
        'tagged'     => 0,
        'contacts'   => 0,
        'families'   => $families,
        'volunteers' => $volunteers,
    ];

    $tag_id = sma_tag_id_by_slug(sma_event_tag_slug($event_slug));
    if (!$tag_id) {
        return $out;
    }

    $tagged = sma_contact_ids_with_tags([$tag_id]);
    $out['tagged'] = count($tagged);

    $key = sma_stage_key($event_slug);
    foreach (sma_family_stages_for($tagged, $event_slug) as $cid => $stage) {
        if (isset($out['families'][$stage])) {
            $out['families'][$stage] += sma_family_row_count($cid, $key);
            $out['contacts']++;
        }
    }

    foreach (sma_volunteers_for_event($event_slug) as $row) {
        $stage = $row['stage'];
        if (isset($out['volunteers'][$stage])) {
            $out['volunteers'][$stage]++;
        }
    }

    return $out;
}

/** Family rows for the event with no stage (e.g. tagged by hand); volunteers are left out. */
function sma_unstaged_family_count($event_slug)
{
    if (!sma_crm_ready()) {
        return 0;
    }
    return count((array) sma_families_for_event($event_slug, 'none'));
}

/** Child counts per stage for the no-event group, plus the no-stage count. */
function sma_no_event_family_counts()
{
    $counts   = array_fill_keys(array_keys(sma_stage_tags('family')), 0);
    $unstaged = 0;
    foreach ((array) sma_families_for_event(SMA_STAGES_NO_EVENT) as $row) {
        if ($row['stage'] === '') {
            $unstaged++;
        } elseif (isset($counts[$row['stage']])) {
            $counts[$row['stage']]++;
        }
    }
    return ['families' => $counts, 'unstaged' => $unstaged];
}

/* ------------------------------------------------------------------------- approval nudge */

// The wait starts at the "sma_stages" changed_at for awaiting-approval, else the contact's created_at.

/** The nudge setting in whole days, at least 1. */
function sma_approval_nudge_days()
{
    return max(1, (int) sma_setting('approval_nudge_days'));
}

/** Whether waiting families of this event (null: the no-event group) are nudged; past events are not. */
function sma_approval_nudge_applies($event)
{
    return !($event instanceof WP_Post) || !sma_event_is_past($event);
}

/** When the family started waiting for approval for this key, in site time. */
function sma_waiting_since(array $map, $key, $contact_created)
{
    if (isset($map[$key]['stage'], $map[$key]['changed_at']) && $map[$key]['stage'] === 'awaiting-approval'
        && (string) $map[$key]['changed_at'] !== '') {
        return (string) $map[$key]['changed_at'];
    }
    return (string) $contact_created;
}

/** Seconds since a site-time date, or null when it can't be read. */
function sma_waiting_seconds($since)
{
    if ((string) $since === '') {
        return null;
    }
    try {
        $at = new DateTimeImmutable((string) $since, wp_timezone());
    } catch (\Exception $e) {
        return null;
    }
    return max(0, time() - $at->getTimestamp());
}

/** Whole days waited since a site-time date (0 on the first day), or null when unknown. */
function sma_waiting_days($since)
{
    $seconds = sma_waiting_seconds($since);
    return $seconds === null ? null : intdiv($seconds, DAY_IN_SECONDS);
}

/** Whether a family waiting since this date has waited longer than the nudge setting. */
function sma_waiting_too_long($since)
{
    $seconds = sma_waiting_seconds($since);
    return $seconds !== null && $seconds > sma_approval_nudge_days() * DAY_IN_SECONDS;
}

/** Families waiting too long, all events or one: [days, total, contact_ids, events => [key => [count, contact_ids, longest_days]]]. */
function sma_families_waiting_too_long($event_slug = null)
{
    $out = ['days' => sma_approval_nudge_days(), 'total' => 0, 'contact_ids' => [], 'events' => []];
    if (!sma_crm_ready()) {
        return $out;
    }

    $groups = [];
    if ($event_slug === null || $event_slug === '' || $event_slug === 'all') {
        foreach (sma_events_published() as $event) {
            if (sma_approval_nudge_applies($event)) {
                $groups[$event->post_name] = $event;
            }
        }
        $groups[SMA_STAGES_NO_EVENT] = null;
    } elseif ((string) $event_slug === SMA_STAGES_NO_EVENT) {
        $groups[SMA_STAGES_NO_EVENT] = null;
    } else {
        $event = sma_event_by_slug((string) $event_slug);
        if (!$event || !sma_approval_nudge_applies($event)) {
            return $out;
        }
        $groups[$event->post_name] = $event;
    }

    global $wpdb;
    $subs = $wpdb->prefix . 'fc_subscribers';
    foreach ($groups as $key => $event) {
        if ($event) {
            $tag_id = sma_tag_id_by_slug(sma_event_tag_slug($event->post_name));
            $ids    = $tag_id ? sma_contact_ids_with_tags([$tag_id]) : [];
        } else {
            $ids = sma_no_event_contact_ids();
        }
        if (!$ids) {
            continue;
        }
        $tags    = sma_stage_tags_for_contacts($ids);
        $id_ph   = implode(',', array_fill(0, count($ids), '%d'));
        $created = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, created_at FROM {$subs} WHERE id IN ({$id_ph})", $ids)) as $c) {
            $created[(int) $c->id] = (string) $c->created_at;
        }
        $found   = [];
        $longest = 0;
        foreach ($ids as $cid) {
            $map   = sma_stage_map($cid);
            $stage = sma_stage_from_map($map, $key, isset($tags[$cid]['family']) ? $tags[$cid]['family'] : '');
            if ($stage !== 'awaiting-approval') {
                continue;
            }
            $since = sma_waiting_since($map, $key, isset($created[$cid]) ? $created[$cid] : '');
            if (sma_waiting_too_long($since)) {
                $found[] = (int) $cid;
                $longest = max($longest, (int) sma_waiting_days($since));
            }
        }
        if ($found) {
            sort($found);
            $out['events'][$key] = ['count' => count($found), 'contact_ids' => $found, 'longest_days' => $longest];
            $out['total']       += count($found);
            $out['contact_ids']  = array_merge($out['contact_ids'], $found);
        }
    }
    $out['contact_ids'] = array_values(array_unique($out['contact_ids']));
    sort($out['contact_ids']);
    return $out;
}

/* --------------------------------------------------------------------- attended, canvases */

/** Whether the event is today or past in its own zone, so families can be marked attended in bulk. */
function sma_event_attendance_open(WP_Post $event)
{
    $date = trim((string) get_post_meta($event->ID, 'event_date', true));
    return $date !== '' && $date <= sma_event_today($event);
}

/** Attended families whose canvas isn't delivered, one row per family per event, oldest event first. */
function sma_canvas_rows()
{
    $rows   = [];
    $events = sma_events_published();
    $events[] = null;
    foreach ($events as $event) {
        $key   = $event ? $event->post_name : SMA_STAGES_NO_EVENT;
        $title = 'Individual session / no event';
        $date  = '';
        if ($event) {
            $details = sma_event_details($event);
            $title   = trim($details['city'] . ' ' . $details['event_date']);
            $date    = (string) $details['event_date'];
        }
        $seen = [];
        foreach ((array) sma_families_for_event($key, 'attended') as $f) {
            $cid = (int) $f['contact_id'];
            if (isset($seen[$cid])) {
                continue; // a sibling's row: the canvas is the family's
            }
            $seen[$cid] = true;
            $canvas     = is_array($f['canvas']) ? $f['canvas'] : sma_canvas_for($cid, $key);
            if ($canvas['status'] === 'delivered') {
                continue;
            }
            $rows[] = [
                'contact_id'  => $cid,
                'event_slug'  => $key,
                'event_title' => $title,
                'event_date'  => $date,
                'first_name'  => $f['first_name'],
                'last_name'   => $f['last_name'],
                'email'       => $f['email'],
                'children'    => sma_family_child_first_names($cid, $key),
                'attended_at' => $f['stage_since'],
                'canvas'      => $canvas,
            ];
        }
    }
    usort($rows, function ($a, $b) {
        $da = $a['event_date'] !== '' ? $a['event_date'] : substr($a['attended_at'], 0, 10);
        $db = $b['event_date'] !== '' ? $b['event_date'] : substr($b['attended_at'], 0, 10);
        if ($da !== $db) {
            // Unknown dates last.
            if ($da === '' || $db === '') {
                return $da === '' ? 1 : -1;
            }
            return strcmp($da, $db);
        }
        $ta = $a['attended_at'] . '|' . $a['last_name'] . '|' . $a['first_name'];
        $tb = $b['attended_at'] . '|' . $b['last_name'] . '|' . $b['first_name'];
        return strcmp($ta, $tb) ?: ($a['contact_id'] - $b['contact_id']);
    });
    return $rows;
}

/* ------------------------------------------------------------------------------ volunteers */

/** One volunteer row in the dashboard and CSV shape. */
function sma_volunteer_event_row(array $v, $slug, $stage, array $entry = [])
{
    $role = isset($entry['role']) && $entry['role'] !== '' ? (string) $entry['role'] : (string) ($v['role'] ?? '');
    return [
        'event_slug'         => (string) $slug,
        'no_event'           => $slug === '' || $slug === SMA_STAGES_NO_EVENT,
        'contact_id'         => (int) ($v['contact_id'] ?? 0),
        'first_name'         => (string) ($v['first_name'] ?? ''),
        'last_name'          => (string) ($v['last_name'] ?? ''),
        'email'              => (string) ($v['email'] ?? ''),
        'phone'              => (string) ($v['phone'] ?? ''),
        'role'               => $role,
        'preferred_location' => (string) ($v['preferred_location'] ?? ''),
        'portfolio_url'      => (string) ($v['portfolio_url'] ?? ''),
        'stage'              => (string) $stage,
        'applied_at'         => isset($entry['applied_at']) ? (string) $entry['applied_at'] : (string) ($v['applied_at'] ?? ''),
        'thanked_at'         => isset($entry['thanked_at']) ? (string) $entry['thanked_at'] : '',
    ];
}

/** Volunteer rows for one event, "_none", or all (''): one person per event, with that event's status. */
function sma_volunteer_event_rows($event_slug = '')
{
    try {
        $list = $event_slug !== '' ? sma_volunteers_for_event($event_slug) : sma_volunteers_all();
    } catch (\Throwable $e) {
        error_log('sma: volunteers lookup failed (' . get_class($e) . ')');
        $list = [];
    }

    $rows = [];
    foreach ((array) $list as $v) {
        $v = (array) $v;
        if ($event_slug !== '') {
            // This event's profile entry, for its thanked_at (role and applied_at are the same).
            $own    = isset($v['event_entries'][$event_slug]) && is_array($v['event_entries'][$event_slug]) ? $v['event_entries'][$event_slug] : [];
            $rows[] = sma_volunteer_event_row($v, $event_slug, (string) ($v['stage'] ?? ''), $own);
            continue;
        }
        $entries = isset($v['event_entries']) && is_array($v['event_entries']) ? $v['event_entries'] : [];
        if (!$entries) {
            // An older profile without per-event entries: one row with the latest stage.
            $rows[] = sma_volunteer_event_row($v, SMA_STAGES_NO_EVENT, (string) ($v['stage'] ?? ''));
            continue;
        }
        foreach ($entries as $slug => $entry) {
            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            $rows[] = sma_volunteer_event_row($v, (string) $slug, sma_volunteer_status_stage($status), $entry);
        }
    }
    return $rows;
}

/* -------------------------------------------------------------------------------- thank-yous */

/** Families and volunteers still to thank for an event (id => first name), plus how many were thanked. */
function sma_thank_recipients($event_slug)
{
    $out = ['families' => [], 'volunteers' => [], 'thanked' => 0];
    foreach ((array) sma_families_for_event($event_slug, 'attended') as $row) {
        $cid = (int) $row['contact_id'];
        if (array_key_exists($cid, $out['families'])) {
            continue; // a sibling's row (null marks a family thanked before)
        }
        if ($row['thanked_at'] !== '') {
            $out['thanked']++;
            $out['families'][$cid] = null;
            continue;
        }
        $out['families'][$cid] = $row['first_name'];
    }
    $out['families'] = array_filter($out['families'], function ($name) {
        return $name !== null;
    });
    foreach (sma_volunteer_event_rows($event_slug) as $v) {
        if ($v['stage'] !== 'volunteer-approved') {
            continue;
        }
        if ($v['thanked_at'] !== '') {
            $out['thanked']++;
            continue;
        }
        $out['volunteers'][(int) $v['contact_id']] = $v['first_name'];
    }
    return $out;
}
