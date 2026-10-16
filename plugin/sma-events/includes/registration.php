<?php
// Sign-up submission → guardian contact, event tag, stage and acknowledgement; child data never reaches the CRM, the diagnosis is never read.

if (!defined('ABSPATH')) {
    exit;
}

// Contact meta: key => [entry ids], and entry id => {event, choice, flags, at}; no child data.
const SMA_SIGNUPS_META = 'sma_signups';
const SMA_ENTRIES_META = 'sma_entries';

/** A form id: the option if set, else the first form titled with the prefix (cached a day), else 0. */
function sma_find_form_id($option, $title_prefix)
{
    $configured = (int) get_option($option, 0);
    if ($configured > 0) {
        return $configured;
    }

    if (!defined('FLUENTFORM')) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'fluentform_forms';

    // A cached id is used only while that form still exists, so a deleted form can't swallow sign-ups.
    $cache_key = $option . '_auto';
    $cached = (int) get_transient($cache_key);
    if ($cached > 0) {
        $still_there = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $cached));
        if ($still_there === $cached) {
            return $cached;
        }
        delete_transient($cache_key);
    }

    $found = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE title LIKE %s ORDER BY CASE WHEN status = 'published' THEN 0 ELSE 1 END, id ASC LIMIT 1",
        $wpdb->esc_like($title_prefix) . '%'
    ));

    if ($found > 0) {
        set_transient($cache_key, $found, DAY_IN_SECONDS);
    }
    return $found;
}

function sma_signup_form_id()
{
    return sma_find_form_id(SMA_SIGNUP_FORM_OPTION, SMA_SIGNUP_FORM_PREFIX);
}

function sma_volunteer_form_id()
{
    return sma_find_form_id(SMA_VOLUNTEER_FORM_OPTION, SMA_VOLUNTEER_FORM_PREFIX);
}

/** Labels that mark a field as medical whatever its name; errs on the side of hiding. */
function sma_forbidden_label_pattern()
{
    return '/diagnos|medical|condition|illness|disease|health|treat|therap|cancer|oncolog|tumou?r|leuk|lymph|hospital|sick|symptom|fight/i';
}

/** Names of fields whose label or placeholder looks medical (a matching sub-field forbids its parent); null if unreadable. */
function sma_forbidden_field_names($form_id)
{
    static $cache = [];
    $form_id = (int) $form_id;
    if (array_key_exists($form_id, $cache)) {
        return $cache[$form_id];
    }

    $names = null;
    if ($form_id > 0 && defined('FLUENTFORM')) {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
            $form_id
        ));
        $structure = is_string($json) ? json_decode($json, true) : null;
        if (is_array($structure) && isset($structure['fields']) && is_array($structure['fields'])) {
            $found = [];
            sma_forbidden_fields_walk($structure['fields'], sma_forbidden_label_pattern(), $found, '');
            $names = array_keys($found);
        }
    }
    $cache[$form_id] = $names;
    return $names;
}

/** Walk Fluent Forms fields (containers and sub-fields) and collect forbidden top-level names into $found. */
function sma_forbidden_fields_walk($fields, $pattern, array &$found, $top_name)
{
    if (!is_array($fields)) {
        return;
    }
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        if (($field['element'] ?? '') === 'container') {
            foreach ((array) ($field['columns'] ?? []) as $column) {
                if (is_array($column)) {
                    sma_forbidden_fields_walk($column['fields'] ?? [], $pattern, $found, $top_name);
                }
            }
            continue;
        }

        $name  = $top_name !== '' ? $top_name : (string) ($field['attributes']['name'] ?? '');
        $texts = [
            $field['settings']['label'] ?? '',
            $field['settings']['admin_field_label'] ?? '',
            $field['attributes']['placeholder'] ?? '',
            $field['settings']['placeholder'] ?? '',
        ];
        foreach ($texts as $text) {
            if (is_string($text) && $text !== '' && preg_match($pattern, $text)) {
                if ($name !== '') {
                    $found[$name] = true;
                }
                break;
            }
        }

        if (!empty($field['fields']) && is_array($field['fields'])) {
            sma_forbidden_fields_walk($field['fields'], $pattern, $found, $name);
        }
    }
}

/** An entry's data with diagnosis names and medical-looking fields removed; callers still use allow-lists. */
function sma_entry_safe_data(array $data, $form_id)
{
    static $logged = [];
    $deny = sma_diagnosis_field_names();
    $forbidden = sma_forbidden_field_names($form_id);
    if ($forbidden === null) {
        if (empty($logged[(int) $form_id])) {
            error_log('sma: form ' . (int) $form_id . ' structure unreadable; label deny-list not applied');
            $logged[(int) $form_id] = true;
        }
    } else {
        $deny = array_merge($deny, $forbidden);
    }
    foreach ($deny as $name) {
        unset($data[$name]);
    }
    return $data;
}

/** The raw value of a field ("a.b" reads a sub-field), or null. */
function sma_field_value(array $data, $name)
{
    $value = $data;
    foreach (explode('.', (string) $name) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

/** The first non-blank scalar value among the given field names, trimmed, or ''. */
function sma_field(array $data, array $names)
{
    foreach ($names as $name) {
        $value = sma_field_value($data, $name);
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return '';
}

/** The plugin's only read of stored entries: id => [form_id, data (made safe), created_at]. */
function sma_entries_read(array $entry_ids)
{
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', $entry_ids))));
    if (!$entry_ids || !defined('FLUENTFORM')) {
        return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($entry_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, form_id, response, created_at FROM {$wpdb->prefix}fluentform_submissions WHERE id IN ({$placeholders})",
        $entry_ids
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $data = is_string($row->response) ? json_decode($row->response, true) : null;
        $out[(int) $row->id] = [
            'form_id'    => (int) $row->form_id,
            'data'       => is_array($data) ? sma_entry_safe_data($data, (int) $row->form_id) : [],
            'created_at' => (string) $row->created_at,
        ];
        unset($data);
    }
    return $out;
}

/** The child's first name from a stored entry, for an email body; reads nothing else. */
function sma_entry_child_first_name($entry_id)
{
    $entries = sma_entries_read([(int) $entry_id]);
    if (!isset($entries[(int) $entry_id])) {
        return '';
    }
    return sanitize_text_field(sma_field($entries[(int) $entry_id]['data'], sma_signup_fields()['child_first_name']));
}

/** Children's first names for a family and event, oldest first, skipping "name-differs" entries so emails never name another's child. */
function sma_family_child_first_names($contact_id, $key)
{
    $names   = [];
    $records = sma_entry_records($contact_id);
    foreach (sma_signup_entries($contact_id, $key) as $entry_id) {
        $flags = isset($records[(string) $entry_id]['flags']) && is_array($records[(string) $entry_id]['flags'])
            ? $records[(string) $entry_id]['flags'] : [];
        if (in_array('name-differs', $flags, true)) {
            continue;
        }
        $name = sma_entry_child_first_name($entry_id);
        if ($name !== '' && !in_array(sma_child_name_key($name), array_map('sma_child_name_key', $names), true)) {
            $names[] = $name;
        }
    }
    return $names;
}

/** A child first name for comparison: trimmed, lower case. */
function sma_child_name_key($name)
{
    $name = trim((string) $name);
    return function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
}

/* ------------------------------------------------ sign-up entries per contact and event (one per child) ------------------------------------------------ */

/** The contact's sign-ups: key (event slug or "_none") => [entry ids], oldest first. */
function sma_signup_map($contact_id)
{
    $raw = sma_contact_meta_get((int) $contact_id, SMA_SIGNUPS_META, []);
    $map = [];
    if (!is_array($raw)) {
        return $map;
    }
    foreach ($raw as $key => $ids) {
        $key = sma_stage_key($key);
        foreach ((array) $ids as $id) {
            if ((int) $id > 0) {
                $map[$key][] = (int) $id;
            }
        }
    }
    foreach ($map as $key => $ids) {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $map[$key] = $ids;
    }
    return $map;
}

/** Entry ids for one contact and event (or "_none"), oldest first. */
function sma_signup_entries($contact_id, $key)
{
    $map = sma_signup_map($contact_id);
    $key = sma_stage_key($key);
    return isset($map[$key]) ? $map[$key] : [];
}

/** Per-entry records: entry id => {event, choice, flags, at}. */
function sma_entry_records($contact_id)
{
    $records = sma_contact_meta_get((int) $contact_id, SMA_ENTRIES_META, []);
    return is_array($records) ? $records : [];
}

/** Entry flag slug => dashboard label. */
function sma_entry_flag_labels()
{
    return [
        'possible-duplicate' => 'Possible duplicate',
        'name-differs'       => 'Name differs from contact',
    ];
}

/** Record a new entry for a contact and event (never replacing an earlier one), with its dropdown choice and flags. */
function sma_signup_add($contact_id, $key, $entry_id, $choice = '', array $flags = [])
{
    $contact_id = (int) $contact_id;
    $entry_id   = (int) $entry_id;
    $key        = sma_stage_key($key);
    if ($contact_id <= 0 || $entry_id <= 0) {
        return;
    }

    $map = sma_signup_map($contact_id);
    $ids = isset($map[$key]) ? $map[$key] : [];
    $ids[] = $entry_id;
    $ids = array_values(array_unique($ids));
    sort($ids);
    $map[$key] = $ids;
    sma_contact_meta_set($contact_id, SMA_SIGNUPS_META, $map);

    $known   = sma_entry_flag_labels();
    $records = sma_entry_records($contact_id);
    $records[(string) $entry_id] = [
        'event'  => $key,
        'choice' => sanitize_text_field((string) $choice),
        'flags'  => array_values(array_intersect(array_unique($flags), array_keys($known))),
        'at'     => current_time('mysql'),
    ];
    sma_contact_meta_set($contact_id, SMA_ENTRIES_META, $records);
    sma_contact_meta_set($contact_id, 'sma_last_entry_id', $entry_id);
}

/* ------------------------------------------------ submission ------------------------------------------------ */

// Fluent Forms 6.2.5 fires this after saving an entry ($insertId, $formData, $form).
add_action('fluentform/submission_inserted', 'sma_on_form_submission', 20, 3);

function sma_on_form_submission($entry_id, $form_data, $form)
{
    $form_id = (is_object($form) && isset($form->id)) ? (int) $form->id : 0;
    if (!$form_id || !is_array($form_data) || $form_id !== sma_signup_form_id()) {
        return;
    }

    try {
        $result = sma_register_family((int) $entry_id, $form_data, $form_id);
        if (is_wp_error($result)) {
            error_log('sma: sign-up entry ' . (int) $entry_id . ' not linked: ' . $result->get_error_code());
        }
    } catch (\Throwable $e) {
        error_log('sma: sign-up entry ' . (int) $entry_id . ' failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

/** True when the contact is approved or booked for another upcoming event. */
function sma_family_holds_other_event($contact, $except_key)
{
    $except_key = sma_stage_key($except_key);
    foreach (sma_event_stages($contact) as $key => $stage) {
        if ($key === $except_key || $key === SMA_STAGES_NO_EVENT || !sma_stage_is_confirmed($stage)) {
            continue;
        }
        $event = sma_choice_event($key);
        if ($event && !sma_event_is_past($event)) {
            return true;
        }
    }
    return false;
}

/** Set a family's stage for one event from a sign-up; the stage tag stays if another upcoming event is confirmed. */
function sma_signup_set_stage($contact, $stage, $key)
{
    return sma_family_holds_other_event($contact, $key)
        ? sma_set_event_stage_only($contact, $stage, $key)
        : sma_set_stage($contact, $stage, $key);
}

/** True when one of these entries has the same child first name (trimmed, any case). */
function sma_child_already_signed_up(array $entry_ids, $child_first_name)
{
    $wanted = sma_child_name_key($child_first_name);
    if ($wanted === '' || !$entry_ids) {
        return false;
    }
    $field = sma_signup_fields()['child_first_name'];
    foreach (sma_entries_read($entry_ids) as $entry) {
        if (sma_child_name_key(sma_field($entry['data'], $field)) === $wanted) {
            return true;
        }
    }
    return false;
}

/** Register one sign-up entry (one child): contact, entry, duplicate and name checks, stage, acknowledgement; returns the contact id. */
function sma_register_family($entry_id, array $data, $form_id = 0)
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }

    $data   = sma_entry_safe_data($data, $form_id > 0 ? (int) $form_id : (int) sma_signup_form_id());
    $fields = sma_signup_fields();
    $email  = sanitize_email(sma_field($data, $fields['email']));
    if (!$email || !is_email($email)) {
        return new WP_Error('sma_no_email', 'The sign-up has no valid email address.');
    }

    $first  = sanitize_text_field(sma_field($data, $fields['first_name']));
    $last   = sanitize_text_field(sma_field($data, $fields['last_name']));
    $child  = sanitize_text_field(sma_field($data, $fields['child_first_name']));
    $choice = sanitize_text_field(sma_field($data, $fields['event']));
    $event  = sma_choice_event($choice);
    $key    = $event ? $event->post_name : SMA_STAGES_NO_EVENT;

    // Guardian fields only. Nothing about the child goes into the CRM.
    $upsert = sma_upsert_contact([
        'email'      => $email,
        'first_name' => $first,
        'last_name'  => $last,
        'phone'      => sanitize_text_field(sma_field($data, $fields['phone'])),
    ]);
    if (is_wp_error($upsert)) {
        return $upsert;
    }
    $contact    = $upsert['contact'];
    $contact_id = (int) $contact->id;

    $name_differs = $upsert['existing'] && (
        sma_names_differ($first, $upsert['before']['first_name'])
        || sma_names_differ($last, $upsert['before']['last_name'])
    );
    $duplicate      = $child !== '' && sma_child_already_signed_up(sma_signup_entries($contact_id, $key), $child);
    $previous_stage = sma_event_stage($contact, $key);

    if ($event) {
        $tag_id = sma_event_ensure_tag($event);
        if ($tag_id) {
            $contact->attachTags([$tag_id]);
        }
    } elseif ($choice !== '' && !sma_events_keep_option(['value' => $choice, 'label' => $choice])) {
        // A dated choice with no event record (a stale option): filed under "_none".
        error_log('sma: sign-up entry ' . (int) $entry_id . ' names an event with no event record');
    }

    $flags = [];
    if ($duplicate) {
        $flags[] = 'possible-duplicate';
    }
    if ($name_differs) {
        $flags[] = 'name-differs';
    }
    sma_signup_add($contact_id, $key, (int) $entry_id, $choice, $flags);

    $where = 'Sign-up form entry #' . (int) $entry_id . ' for ' . $key . '.';
    if ($name_differs && sma_stage_kept_on_signup($previous_stage)) {
        // A public form never moves a confirmed, attended or follow-up family: flag and note only.
        $staged = true;
        sma_add_contact_note($contact_id, 'Sign-up name differs (stage kept)', $where . ' The guardian name on the form differs from this contact, who is ' . $previous_stage . ' for this event. Nothing on the contact or the stage was changed. Please check.');
    } elseif ($name_differs) {
        $staged = sma_signup_set_stage($contact, 'needs-follow-up', $key);
        sma_record_decision($contact_id, $key, 'needs-follow-up', 'Sign-up name differs from the contact on file');
        sma_add_contact_note($contact_id, 'Sign-up name differs', $where . ' The guardian name on the form differs from this contact. The contact was not changed. Please check.');
    } elseif ($duplicate) {
        $staged = $previous_stage === '' ? sma_signup_set_stage($contact, 'awaiting-approval', $key) : true;
        sma_add_contact_note($contact_id, 'Possible duplicate sign-up', $where . ' Same email, child first name and event as an earlier entry. Nothing was merged; no email sent.');
    } elseif (sma_stage_kept_on_signup($previous_stage)) {
        $staged = true;
        sma_add_contact_note($contact_id, 'Signed up again (stage kept)', $where);
    } else {
        $staged = sma_signup_set_stage($contact, 'awaiting-approval', $key);
        sma_add_contact_note($contact_id, 'Signed up, awaiting approval', $where);
    }
    if (is_wp_error($staged)) {
        return $staged;
    }

    // A family whose stage is kept gets no acknowledgement; nothing changed for them.
    $send_ack = !$duplicate && !sma_stage_kept_on_signup($previous_stage);
    if ($send_ack) {
        $details = $event ? sma_event_details($event) : [];
        // A name mismatch may be someone else using this address: repeat nothing from the form.
        $greet = $name_differs ? trim((string) $upsert['before']['first_name']) : $first;
        $sent  = sma_send_email((string) $contact->email, 'family-received', [
            'guardian_first_name' => $greet !== '' ? $greet : 'there',
            'child_first_name'    => (!$name_differs && $child !== '') ? $child : 'your child',
            'event_label'         => $details['label'] ?? '',
        ]);
        if (is_wp_error($sent)) {
            error_log('sma: acknowledgement not sent for entry ' . (int) $entry_id . ': ' . $sent->get_error_code());
        }
    }

    return $contact_id;
}
