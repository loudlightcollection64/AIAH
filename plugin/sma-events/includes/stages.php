<?php
// Stage vocabulary and FluentCRM tag helpers: stage tags hold the latest stage; "sma_stages" meta holds each event's.

if (!defined('ABSPATH')) {
    exit;
}

const SMA_EVENT_TAG_PREFIX = 'event-';

/** Stage tags by group: slug => title. */
function sma_stage_groups()
{
    return [
        'family' => [
            'awaiting-approval' => 'Awaiting approval',
            'approved'          => 'Approved',
            'booked'            => 'Booked',
            'attended'          => 'Attended',
            'canceled'          => 'Canceled',
            'needs-follow-up'   => 'Needs follow-up',
        ],
        'volunteer' => [
            'volunteer-applied'  => 'Volunteer: applied',
            'volunteer-approved' => 'Volunteer: approved',
            'volunteer-declined' => 'Volunteer: declined',
        ],
    ];
}

/** Stage tags as slug => title for 'family', 'volunteer', or both (null). */
function sma_stage_tags($group = null)
{
    $groups = sma_stage_groups();
    if ($group !== null) {
        return $groups[$group] ?? [];
    }

    $all = [];
    foreach ($groups as $tags) {
        $all = array_merge($all, $tags);
    }
    return $all;
}

/** The group a stage belongs to, or '' if it is not a stage. */
function sma_stage_group($stage)
{
    foreach (sma_stage_groups() as $group => $tags) {
        if (isset($tags[$stage])) {
            return $group;
        }
    }
    return '';
}

function sma_crm_ready()
{
    return function_exists('FluentCrmApi')
        && class_exists('\FluentCrm\App\Models\Tag')
        && class_exists('\FluentCrm\App\Models\Subscriber');
}

function sma_event_tag_slug($event_slug)
{
    return SMA_EVENT_TAG_PREFIX . sanitize_title((string) $event_slug);
}

/** The tag id for a slug without creating it, or 0. */
function sma_tag_id_by_slug($slug)
{
    if (!sma_crm_ready()) {
        return 0;
    }
    try {
        $tag = \FluentCrm\App\Models\Tag::where('slug', $slug)->first();
        return $tag ? (int) $tag->id : 0;
    } catch (\Throwable $e) {
        error_log('sma: tag lookup failed (' . get_class($e) . ')');
        return 0;
    }
}

/** The tag id for a slug, creating it with this title if missing; 0 without FluentCRM. */
function sma_get_or_create_tag($slug, $title)
{
    $slug = sanitize_title((string) $slug);
    if ($slug === '' || !sma_crm_ready()) {
        return 0;
    }

    $existing = sma_tag_id_by_slug($slug);
    if ($existing) {
        return $existing;
    }

    try {
        $api = FluentCrmApi('tags');
        if (is_object($api) && method_exists($api, 'importBulk')) {
            // Fires FluentCRM's own tag_created hooks.
            $created = $api->importBulk([['title' => $title, 'slug' => $slug]]);
            if (!empty($created[0]->id)) {
                return (int) $created[0]->id;
            }
        }

        $tag = \FluentCrm\App\Models\Tag::create([
            'title' => sanitize_text_field($title),
            'slug'  => $slug,
        ]);
        return $tag ? (int) $tag->id : 0;
    } catch (\Throwable $e) {
        error_log('sma: tag create failed (' . get_class($e) . ')');
        return 0;
    }
}

/** Resolve a contact from a Subscriber, an id or an email, or null. */
function sma_contact($contact_or_id)
{
    if (!sma_crm_ready()) {
        return null;
    }
    if ($contact_or_id instanceof \FluentCrm\App\Models\Subscriber) {
        return $contact_or_id;
    }

    try {
        if (is_numeric($contact_or_id) && (int) $contact_or_id > 0) {
            $found = \FluentCrm\App\Models\Subscriber::where('id', (int) $contact_or_id)->first();
            return $found ?: null;
        }
        if (is_string($contact_or_id) && is_email($contact_or_id)) {
            return sma_contact_by_email($contact_or_id);
        }
    } catch (\Throwable $e) {
        error_log('sma: contact lookup failed (' . get_class($e) . ')');
    }
    return null;
}

/** The contact with this email, ignoring case (exact match first, then LOWER() for SQLite); the plugin's only email lookup. */
function sma_contact_by_email($email)
{
    $email = sanitize_email((string) $email);
    if (!$email || !sma_crm_ready()) {
        return null;
    }
    try {
        $found = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
        if (!$found) {
            $found = \FluentCrm\App\Models\Subscriber::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        }
        return $found ?: null;
    } catch (\Throwable $e) {
        error_log('sma: contact lookup failed (' . get_class($e) . ')');
        return null;
    }
}

/** Status for a new contact ("transactional": a sign-up isn't newsletter consent); null keeps an existing contact's status. */
function sma_contact_status_for($email)
{
    if (sma_contact_by_email($email)) {
        return null;
    }
    $status = sanitize_key((string) apply_filters('sma_new_contact_status', 'transactional', $email));
    if (function_exists('fluentcrm_subscriber_statuses')) {
        $valid = (array) fluentcrm_subscriber_statuses();
        if (!in_array($status, $valid, true)) {
            $status = 'transactional';
        }
    }
    return $status !== '' ? $status : 'transactional';
}

/** All tag slugs on a contact, freshly loaded. */
function sma_contact_tag_slugs($contact)
{
    $contact = sma_contact($contact);
    if (!$contact) {
        return [];
    }
    $contact->load('tags');

    $slugs = [];
    foreach ($contact->tags as $tag) {
        $slugs[] = (string) $tag->slug;
    }
    return $slugs;
}

/** The contact's current stage tag in a group, or ''. */
function sma_get_stage($contact, $group = 'family')
{
    $stages = sma_stage_tags($group);
    foreach (sma_contact_tag_slugs($contact) as $slug) {
        if (isset($stages[$slug])) {
            return $slug;
        }
    }
    return '';
}

/** Event slugs the contact is tagged with. */
function sma_contact_events($contact)
{
    $events = [];
    $prefix_len = strlen(SMA_EVENT_TAG_PREFIX);
    foreach (sma_contact_tag_slugs($contact) as $slug) {
        if (strpos($slug, SMA_EVENT_TAG_PREFIX) === 0) {
            $events[] = substr($slug, $prefix_len);
        }
    }
    return $events;
}

/* ------------------------------------------------ per-event family stage ------------------------------------------------ */

// Contact meta: { <event slug or "_none">: { stage, changed_at, by } }; a contact with no map falls back to its stage tag.
const SMA_STAGES_META = 'sma_stages';
const SMA_STAGES_NO_EVENT = '_none';

/** The sma_event a dropdown value or stored key names, or null ("no event"); cached per request. */
function sma_choice_event($choice)
{
    static $cache = [];
    $slug = sanitize_title((string) $choice);
    if ($slug === '' || $slug === SMA_STAGES_NO_EVENT) {
        return null;
    }
    if (!array_key_exists($slug, $cache)) {
        $cache[$slug] = sma_event_by_slug($slug);
    }
    return $cache[$slug];
}

/** The stage-map key for a dropdown value or event slug: the event's slug, else "_none". */
function sma_stage_key($event_slug)
{
    $event = sma_choice_event($event_slug);
    return $event ? $event->post_name : SMA_STAGES_NO_EVENT;
}

/* ------------------------------------------------------------- shared family stage rules */

/** A family whose place at the event is confirmed: approved or booked. */
function sma_stage_is_confirmed($stage)
{
    return in_array((string) $stage, ['approved', 'booked'], true);
}

/** Whether a family keeps this stage (and gets no new acknowledgement) when it signs up again. */
function sma_stage_kept_on_signup($stage)
{
    return sma_stage_is_confirmed($stage) || in_array((string) $stage, ['attended', 'needs-follow-up'], true);
}

/** Whether a family in this stage may be approved; never without an event, since approval emails a booking link. */
function sma_can_approve($stage, $key = '')
{
    if ((string) $key === SMA_STAGES_NO_EVENT) {
        return false;
    }
    return in_array((string) $stage, ['', 'awaiting-approval', 'needs-follow-up', 'canceled'], true);
}

/** Whether a family in this stage may be declined (a booked or attended family is not). */
function sma_can_decline($stage)
{
    return in_array((string) $stage, ['', 'awaiting-approval', 'needs-follow-up', 'approved'], true);
}

/** The contact's per-event stage map, always an array (key => [stage, changed_at, by]). */
function sma_stage_map($contact_id)
{
    $map = sma_contact_meta_get((int) $contact_id, SMA_STAGES_META, []);
    return is_array($map) ? $map : [];
}

/** The stage for one event from a loaded map; $tag_stage is used only when the map is empty. */
function sma_stage_from_map(array $map, $key, $tag_stage)
{
    if (!$map) {
        return (string) $tag_stage;
    }
    $families = sma_stage_tags('family');
    if (isset($map[$key]['stage']) && isset($families[(string) $map[$key]['stage']])) {
        return (string) $map[$key]['stage'];
    }
    return '';
}

/** The family's stage for one event (its map entry, or the stage tag when it has no map), or ''. */
function sma_event_stage($contact, $event_slug)
{
    $contact = sma_contact($contact);
    if (!$contact) {
        return '';
    }
    $map = sma_stage_map($contact->id);
    return sma_stage_from_map($map, sma_stage_key($event_slug), $map ? '' : sma_get_stage($contact, 'family'));
}

/** Every event's family stage for this contact: key (slug or "_none") => stage. */
function sma_event_stages($contact)
{
    $contact = sma_contact($contact);
    if (!$contact) {
        return [];
    }
    $map = sma_stage_map($contact->id);
    $out = [];
    if ($map) {
        foreach (array_keys($map) as $key) {
            $stage = sma_stage_from_map($map, (string) $key, '');
            if ($stage !== '') {
                $out[(string) $key] = $stage;
            }
        }
        return $out;
    }
    $tagged = sma_get_stage($contact, 'family');
    if ($tagged !== '') {
        foreach (sma_stage_legacy_keys($contact) as $key) {
            $out[$key] = $tagged;
        }
    }
    return $out;
}

/** Family-event keys of a contact with no map: its sign-ups plus event tags that aren't volunteer-only. */
function sma_stage_legacy_keys($contact)
{
    $signups = sma_contact_meta_get($contact->id, 'sma_signups', []);
    $signups = is_array($signups) ? $signups : [];
    $volunteer = sma_contact_meta_get($contact->id, 'sma_volunteer', []);
    $volunteer_events = (is_array($volunteer) && isset($volunteer['events']) && is_array($volunteer['events']))
        ? $volunteer['events']
        : [];

    $keys = [];
    foreach (array_keys($signups) as $key) {
        $keys[] = sma_stage_key($key);
    }
    foreach (sma_contact_events($contact) as $slug) {
        if (isset($signups[$slug]) || !isset($volunteer_events[$slug])) {
            $keys[] = sma_stage_key($slug);
        }
    }
    return array_values(array_unique($keys));
}

/** Before a contact's first map write, copy its tag stage into the map for its other family events. */
function sma_stage_map_backfill($contact, $except_key)
{
    $map = sma_stage_map($contact->id);
    if ($map) {
        return $map;
    }
    $tagged = sma_get_stage($contact, 'family');
    if ($tagged === '') {
        return $map;
    }
    foreach (sma_stage_legacy_keys($contact) as $key) {
        if ($key !== $except_key) {
            $map[$key] = ['stage' => $tagged, 'changed_at' => '', 'by' => 0];
        }
    }
    if ($map) {
        sma_contact_meta_set($contact->id, SMA_STAGES_META, $map);
    }
    return $map;
}

/** Write one event's stage-map entry without moving the stage tag: true or WP_Error. */
function sma_set_event_stage_only($contact_or_id, $stage, $event_slug)
{
    if (sma_stage_group($stage) !== 'family') {
        return new WP_Error('sma_bad_stage', 'Unknown family stage.', ['status' => 400]);
    }
    $contact = sma_contact($contact_or_id);
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'Contact not found.', ['status' => 404]);
    }
    $key  = sma_stage_key($event_slug);
    $map  = sma_stage_map_backfill($contact, $key);
    $user = get_current_user_id();
    $map[$key] = [
        'stage'      => $stage,
        'changed_at' => current_time('mysql'),
        'by'         => $user ? (int) $user : 0,
    ];
    sma_contact_meta_set($contact->id, SMA_STAGES_META, $map);
    return true;
}

/** Move a contact to a stage within its group, and write the event's map entry when a slug is given. */
function sma_set_stage($contact_or_id, $stage, $event_slug = '')
{
    $group = sma_stage_group($stage);
    if ($group === '') {
        return new WP_Error('sma_bad_stage', 'Unknown stage.', ['status' => 400]);
    }
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.', ['status' => 503]);
    }
    $contact = sma_contact($contact_or_id);
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'Contact not found.', ['status' => 404]);
    }

    // Freeze the other events' displayed stage before the tag moves.
    $event_slug = (string) $event_slug;
    if ($group === 'family' && $event_slug !== '') {
        sma_stage_map_backfill($contact, sma_stage_key($event_slug));
    }

    $stages = sma_stage_tags($group);

    try {
        // Resolve the new stage tag first, so a failure never leaves the contact with no stage.
        $tag_id = sma_get_or_create_tag($stage, $stages[$stage]);
        if (!$tag_id) {
            return new WP_Error('sma_tag_failed', 'Could not create the stage tag.', ['status' => 500]);
        }

        $others = array_values(array_diff(array_keys($stages), [$stage]));
        $detach = [];
        foreach (\FluentCrm\App\Models\Tag::whereIn('slug', $others)->get() as $tag) {
            $detach[] = (int) $tag->id;
        }
        if ($detach) {
            $contact->detachTags($detach);
        }

        $contact->attachTags([$tag_id]);
    } catch (\Throwable $e) {
        error_log('sma: set stage failed (' . get_class($e) . ')');
        return new WP_Error('sma_stage_failed', 'Could not change the stage.', ['status' => 500]);
    }

    if ($group === 'family' && $event_slug !== '') {
        return sma_set_event_stage_only($contact, $stage, $event_slug);
    }
    return true;
}

/** Contact meta stored as a JSON string (portable across FluentCRM versions). */
function sma_contact_meta_get($contact_id, $key, $default = null)
{
    if (!function_exists('fluentcrm_get_subscriber_meta')) {
        return $default;
    }
    $raw = fluentcrm_get_subscriber_meta((int) $contact_id, $key, '');
    if (!is_string($raw) || $raw === '') {
        return $default;
    }
    $value = json_decode($raw, true);
    return $value === null ? $default : $value;
}

function sma_contact_meta_set($contact_id, $key, $value)
{
    if (!function_exists('fluentcrm_update_subscriber_meta')) {
        return false;
    }
    fluentcrm_update_subscriber_meta((int) $contact_id, $key, wp_json_encode($value));
    return true;
}

/** Add a plain-text note to the contact's FluentCRM profile; false when notes are unavailable. */
function sma_add_contact_note($contact_id, $title, $text, $type = 'note')
{
    if (!class_exists('\FluentCrm\App\Models\SubscriberNote')) {
        return false;
    }
    try {
        \FluentCrm\App\Models\SubscriberNote::create([
            'subscriber_id' => (int) $contact_id,
            'type'          => sanitize_key($type),
            'title'         => sanitize_text_field($title),
            'description'   => nl2br(esc_html($text)),
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql'),
        ]);
        return true;
    } catch (\Throwable $e) {
        error_log('sma: note create failed (' . get_class($e) . ')');
        return false;
    }
}

/** Keep a rendered, unsent email as a contact note marked as a draft. */
function sma_add_draft_note($contact_id, $title, array $draft)
{
    $text = "DRAFT, NOT SENT. Edit it and send it yourself if you want to.\n\n"
        . 'Subject: ' . $draft['subject'] . "\n\n" . $draft['body'];
    return sma_add_contact_note($contact_id, $title, $text);
}

/** Record the last decision per event key in "sma_decisions" (volunteers use "volunteer:<slug>"); no family data in $detail. */
function sma_record_decision($contact_id, $event_slug, $action, $detail = '')
{
    $user = wp_get_current_user();
    $decisions = sma_contact_meta_get($contact_id, 'sma_decisions', []);
    if (!is_array($decisions)) {
        $decisions = [];
    }
    $decisions[$event_slug !== '' ? $event_slug : SMA_STAGES_NO_EVENT] = [
        'action'  => $action,
        'detail'  => $detail,
        'by'      => $user && $user->ID ? (int) $user->ID : 0,
        'by_name' => $user && $user->ID ? $user->display_name : 'system',
        'at'      => current_time('mysql'),
    ];
    sma_contact_meta_set($contact_id, 'sma_decisions', $decisions);
}

/* ------------------------------------------------ contact upsert from a public form ------------------------------------------------ */

/** Create a contact, or fill only the empty fields of an existing one (never its names, phone or status). */
function sma_upsert_contact(array $payload)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }
    $email = sanitize_email(isset($payload['email']) ? (string) $payload['email'] : '');
    if (!$email || !is_email($email)) {
        return new WP_Error('sma_no_email', 'No valid email address.');
    }

    $existing = sma_contact_by_email($email);
    $before   = $existing
        ? ['first_name' => (string) $existing->first_name, 'last_name' => (string) $existing->last_name]
        : [];

    $data = [];
    foreach (['first_name', 'last_name', 'phone'] as $field) {
        $value = isset($payload[$field]) ? trim((string) $payload[$field]) : '';
        if ($value === '' || ($existing && trim((string) $existing->{$field}) !== '')) {
            continue;
        }
        $data[$field] = $value;
    }
    $data['email'] = $existing ? (string) $existing->email : $email;
    if (!$existing) {
        $data['status'] = sma_contact_status_for($email);
    }

    try {
        // FluentCRM 3.1.8 Api/Classes/Contacts.php:123.
        $contact = FluentCrmApi('contacts')->createOrUpdate($data);
    } catch (\Throwable $e) {
        error_log('sma: contact upsert failed (' . get_class($e) . ')');
        $contact = null;
    }
    if (!$contact || empty($contact->id)) {
        return new WP_Error('sma_contact_failed', 'Could not create the contact.');
    }
    return ['contact' => $contact, 'existing' => (bool) $existing, 'before' => $before];
}

/** Case-insensitive, trimmed comparison of two names; an empty side never differs. */
function sma_names_differ($a, $b)
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    if ($a === '' || $b === '') {
        return false;
    }
    $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    return $lower($a) !== $lower($b);
}

/* ------------------------------------------------ FluentCRM tag pivot queries ------------------------------------------------ */

const SMA_TAG_PIVOT_TYPE = 'FluentCrm\App\Models\Tag';

/** The prefixed name of FluentCRM's contact-tag pivot table. */
function sma_tag_pivot_table()
{
    global $wpdb;
    return $wpdb->prefix . 'fc_subscriber_pivot';
}

/** Contact ids holding every tag in $all_of_tag_ids and, if given, at least one of $any_of_tag_ids. */
function sma_contact_ids_with_tags(array $all_of_tag_ids, array $any_of_tag_ids = [])
{
    $all_of = array_values(array_unique(array_filter(array_map('intval', $all_of_tag_ids))));
    $any_of = array_values(array_unique(array_filter(array_map('intval', $any_of_tag_ids))));
    if ((!$all_of && !$any_of) || !sma_crm_ready()) {
        return [];
    }

    global $wpdb;
    $pivot = sma_tag_pivot_table();
    $type  = SMA_TAG_PIVOT_TYPE;

    $ids = null;
    if ($all_of) {
        $in  = implode(',', array_fill(0, count($all_of), '%d'));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT subscriber_id FROM {$pivot}
              WHERE object_type = %s AND object_id IN ({$in})
              GROUP BY subscriber_id
             HAVING COUNT(DISTINCT object_id) = %d",
            array_merge([$type], $all_of, [count($all_of)])
        ));
        $ids = array_map('intval', (array) $ids);
        if (!$ids) {
            return [];
        }
    }
    if ($any_of) {
        $in  = implode(',', array_fill(0, count($any_of), '%d'));
        $any = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT subscriber_id FROM {$pivot} WHERE object_type = %s AND object_id IN ({$in})",
            array_merge([$type], $any_of)
        ));
        $any = array_map('intval', (array) $any);
        $ids = $ids === null ? $any : array_values(array_intersect($ids, $any));
    }
    return array_values(array_unique((array) $ids));
}

/** Tag ids (slug => id) for these slugs; missing tags are left out. */
function sma_tag_ids_by_slugs(array $slugs)
{
    $out = [];
    foreach ($slugs as $slug) {
        $id = sma_tag_id_by_slug($slug);
        if ($id) {
            $out[$slug] = $id;
        }
    }
    return $out;
}

/** Ids of every "event-<slug>" tag, whether or not its event still exists. */
function sma_event_tag_ids()
{
    if (!sma_crm_ready()) {
        return [];
    }
    global $wpdb;
    $tags = $wpdb->prefix . 'fc_tags';
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tags} WHERE slug LIKE %s",
        $wpdb->esc_like(SMA_EVENT_TAG_PREFIX) . '%'
    )));
}

/** Each contact's stage tags in one query: id => ['family' => slug, 'volunteer' => slug]. */
function sma_stage_tags_for_contacts(array $contact_ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $contact_ids))));
    if (!$ids || !sma_crm_ready()) {
        return [];
    }

    $group_of = [];
    foreach (sma_stage_groups() as $group => $tags) {
        foreach (array_keys($tags) as $slug) {
            $group_of[$slug] = $group;
        }
    }
    $slugs = array_keys($group_of);

    global $wpdb;
    $pivot    = sma_tag_pivot_table();
    $tags     = $wpdb->prefix . 'fc_tags';
    $id_ph    = implode(',', array_fill(0, count($ids), '%d'));
    $stage_ph = implode(',', array_fill(0, count($slugs), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.subscriber_id AS sid, t.slug AS slug
           FROM {$pivot} p
           INNER JOIN {$tags} t ON t.id = p.object_id
          WHERE p.object_type = %s AND p.subscriber_id IN ({$id_ph}) AND t.slug IN ({$stage_ph})",
        array_merge([SMA_TAG_PIVOT_TYPE], $ids, $slugs)
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $slug = (string) $row->slug;
        $out[(int) $row->sid][$group_of[$slug]] = $slug;
    }
    return $out;
}
