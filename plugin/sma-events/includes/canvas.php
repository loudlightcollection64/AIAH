<?php
/** Canvas tracking per family per event in contact meta "sma_canvases": photos edited, sent to print, delivered; never a stage, never emailed. */

if (!defined('ABSPATH')) {
    exit;
}

const SMA_CANVAS_META = 'sma_canvases';

/** The steps in order, status => label; a family with no entry is "Not started". */
function sma_canvas_steps()
{
    return [
        'edited'    => 'Photos edited',
        'printed'   => 'Sent to print',
        'delivered' => 'Delivered',
    ];
}

/** How a delivered canvas reached the family, value => label. */
function sma_canvas_delivery_ways()
{
    return [
        'shipped'   => 'Shipped',
        'presented' => 'Presented',
    ];
}

/** The family's canvas entry for one event key, with every field present ('' when not reached). */
function sma_canvas_for($contact_id, $key)
{
    $all   = sma_contact_meta_get((int) $contact_id, SMA_CANVAS_META, []);
    $entry = (is_array($all) && isset($all[$key]) && is_array($all[$key])) ? $all[$key] : [];
    $steps = sma_canvas_steps();
    $ways  = sma_canvas_delivery_ways();
    $out   = ['status' => ''];
    if (isset($entry['status']) && isset($steps[(string) $entry['status']])) {
        $out['status'] = (string) $entry['status'];
    }
    foreach (array_keys($steps) as $step) {
        $out[$step . '_at'] = isset($entry[$step . '_at']) ? (string) $entry[$step . '_at'] : '';
    }
    $out['delivered_how'] = (isset($entry['delivered_how']) && isset($ways[(string) $entry['delivered_how']])) ? (string) $entry['delivered_how'] : '';
    return $out;
}

/** The step after this status, or '' once delivered. */
function sma_canvas_next_step($status)
{
    $steps = array_keys(sma_canvas_steps());
    if ((string) $status === '') {
        return $steps[0];
    }
    $at = array_search((string) $status, $steps, true);
    return ($at !== false && isset($steps[$at + 1])) ? $steps[$at + 1] : '';
}

/** A status label such as "Sent to print, 2026-10-20" or "Not started". */
function sma_canvas_label(array $canvas)
{
    $status = isset($canvas['status']) ? (string) $canvas['status'] : '';
    if ($status === '') {
        return 'Not started';
    }
    $steps = sma_canvas_steps();
    $label = $steps[$status];
    if ($status === 'delivered' && !empty($canvas['delivered_how'])) {
        $ways   = sma_canvas_delivery_ways();
        $label .= ' (' . strtolower($ways[$canvas['delivered_how']]) . ')';
    }
    $at = isset($canvas[$status . '_at']) ? (string) $canvas[$status . '_at'] : '';
    return $at !== '' ? $label . ', ' . substr($at, 0, 10) : $label;
}

/** Record the next canvas step for an attended family; $step must be the next one, and delivery needs $how. */
function sma_canvas_advance($contact_id, $event_slug, $step, $how = '')
{
    if (!sma_crm_ready()) {
        return new WP_Error('sma_crm_missing', 'FluentCRM is not active.');
    }
    $contact = sma_contact((int) $contact_id);
    if (!$contact) {
        return new WP_Error('sma_no_contact', 'Contact not found.');
    }
    $key = sma_stage_key($event_slug);
    if ($key === SMA_STAGES_NO_EVENT && (string) $event_slug !== SMA_STAGES_NO_EVENT) {
        return new WP_Error('sma_no_event', 'Event not found.');
    }
    if (sma_event_stage($contact, $key) !== 'attended') {
        return new WP_Error('sma_canvas_not_attended', 'Only a family marked attended has a canvas to track.');
    }

    $canvas = sma_canvas_for($contact->id, $key);
    if ((string) $step !== sma_canvas_next_step($canvas['status'])) {
        return new WP_Error('sma_canvas_step', 'That is not the next canvas step.');
    }
    $ways = sma_canvas_delivery_ways();
    if ($step === 'delivered' && !isset($ways[(string) $how])) {
        return new WP_Error('sma_canvas_how', 'Say whether the canvas was shipped or presented.');
    }

    $all = sma_contact_meta_get($contact->id, SMA_CANVAS_META, []);
    if (!is_array($all)) {
        $all = [];
    }
    $entry = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
    $entry['status']      = (string) $step;
    $entry[$step . '_at'] = current_time('mysql');
    if ($step === 'delivered') {
        $entry['delivered_how'] = (string) $how;
    }
    $user        = get_current_user_id();
    $entry['by'] = $user ? (int) $user : 0;
    $all[$key]   = $entry;
    sma_contact_meta_set($contact->id, SMA_CANVAS_META, $all);
    return true;
}
