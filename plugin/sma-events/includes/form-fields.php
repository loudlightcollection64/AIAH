<?php
// Allow-lists of the Fluent Forms field names the plugin reads (live names from site/dev/live_form.py); never the diagnosis.

if (!defined('ABSPATH')) {
    exit;
}

/** Sign-up field names by role, tried in order ("a.b" reads a sub-field); "names.*" is excluded as it may hold the child's name. */
function sma_signup_fields()
{
    return apply_filters('sma_signup_fields', [
        'first_name'       => ['guardian_first_name', 'guardian_name.first_name', 'input_text_3'],
        'last_name'        => ['guardian_last_name', 'guardian_name.last_name', 'input_text_4'],
        'email'            => ['email'],
        'phone'            => ['cell_phone', 'phone', 'input_mask'],
        'event'            => [SMA_EVENTS_FORM_FIELD, 'dropdown'],
        'child_first_name' => ['child_first_name', 'input_text'],
    ]);
}

/** Field names that hold a child's diagnosis; every entry read strips them. */
function sma_diagnosis_field_names()
{
    return ['diagnosis', 'input_text_1'];
}

/** Child fields the dashboard and export may show; diagnosis names are removed even if a filter adds them. */
function sma_dash_child_field_names()
{
    $names = apply_filters('sma_dash_child_field_names', [
        'child_first_name' => ['child_first_name', 'input_text'],
        'age'              => ['age', 'numeric_field'],
        'costume_theme'    => ['costume_theme', 'input_text_2'],
        'flash_sensitive'  => ['flash_sensitive', 'input_radio'],
    ]);
    return sma_without_diagnosis_names($names);
}

/** Consent checkboxes, shown as yes/no only (Fluent stores a checkbox group as an array of chosen options). */
function sma_dash_consent_field_names()
{
    $names = apply_filters('sma_dash_consent_field_names', [
        'likeness_release' => ['likeness_release', 'checkbox'],
        'social_media_ok'  => ['social_media_ok', 'checkbox_1'],
        'story_ok'         => ['story_ok', 'checkbox_2'],
    ]);
    return sma_without_diagnosis_names($names);
}

/** A role => [names] list with every diagnosis field name taken out. */
function sma_without_diagnosis_names($names)
{
    $never = sma_diagnosis_field_names();
    foreach ((array) $names as $key => $candidates) {
        $names[$key] = array_values(array_diff((array) $candidates, $never));
    }
    return $names;
}

/** Volunteer field names by role, tried in order; portfolio and location stay empty until the live form has them. */
function sma_volunteer_fields()
{
    return apply_filters('sma_volunteer_fields', [
        'first_name'         => ['first_name', 'names.first_name', 'input_text'],
        'last_name'          => ['last_name', 'names.last_name', 'input_text_1'],
        'email'              => ['email'],
        'phone'              => ['phone', 'cell_phone'],
        'role'               => ['role', 'checkbox'],
        'tshirt_size'        => ['tshirt_size', 't_shirt_size', 'dropdown'],
        'preferred_location' => ['preferred_location', 'location'],
        'portfolio_url'      => ['portfolio_url', 'portfolio', 'url'],
        'portfolio_file'     => ['portfolio_file'],
        'event'              => [SMA_EVENTS_FORM_FIELD, 'dropdown_1'],
    ]);
}

/** Names of the event dropdown on a form: the live sign-up and volunteer forms name it differently. */
function sma_event_field_names($form)
{
    $form_id = 0;
    if (is_object($form) && isset($form->id)) {
        $form_id = (int) $form->id;
    } elseif (is_array($form) && isset($form['id'])) {
        $form_id = (int) $form['id'];
    }
    if ($form_id > 0 && $form_id === (int) sma_signup_form_id()) {
        return (array) sma_signup_fields()['event'];
    }
    if ($form_id > 0 && $form_id === (int) sma_volunteer_form_id()) {
        return (array) sma_volunteer_fields()['event'];
    }
    return [SMA_EVENTS_FORM_FIELD];
}
