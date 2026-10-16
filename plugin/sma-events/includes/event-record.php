<?php
// The sma_event record: post type, meta, capabilities, the sign-up dropdown and the edit screen.

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'sma_events_register');

function sma_events_register()
{
    register_post_type(SMA_EVENTS_POST_TYPE, [
        'labels' => [
            'name'          => 'Events',
            'singular_name' => 'Event',
            'add_new_item'  => 'Add new event',
            'edit_item'     => 'Edit event',
        ],
        'public'       => true,
        'show_in_rest' => true,
        'rest_base'    => 'sma-events',
        'menu_icon'    => 'dashicons-calendar-alt',
        'supports'     => ['title', 'editor', 'custom-fields'],
        'rewrite'      => ['slug' => 'events'],
        // Own capabilities, so a restricted account can manage events without edit_posts.
        'capability_type' => ['sma_event', 'sma_events'],
        'map_meta_cap'    => true,
    ]);

    $site_owned = sma_events_site_owned_meta_keys();
    foreach (SMA_EVENTS_META as $key => $type) {
        $args = [
            'type'          => $type,
            'single'        => true,
            'show_in_rest'  => true,
            // Site-owned keys are read-only over REST for everyone; unchanged values still save.
            'auth_callback' => in_array($key, $site_owned, true)
                ? '__return_false'
                : function () {
                    return current_user_can('edit_sma_events');
                },
        ];
        if ($key === 'booking_url') {
            $args['sanitize_callback'] = 'esc_url_raw';
        }
        register_post_meta(SMA_EVENTS_POST_TYPE, $key, $args);
    }
}

/** Event meta hidden from the public: the booking link would let anyone book without approval. */
function sma_events_private_meta_keys()
{
    return ['booking_url', 'booking_calendar_id', 'booking_session_id', 'booking_page_id', 'crm_tag_id',
        'sma_provision_error', 'sma_provisioned_at'];
}

/** Event meta only the plugin writes, so no account can redirect provisioning or inject notice text. */
function sma_events_site_owned_meta_keys()
{
    return ['booking_calendar_id', 'booking_session_id', 'booking_page_id', 'booking_url', 'crm_tag_id',
        'sma_provision_error', 'sma_provisioned_at'];
}

add_filter('rest_prepare_' . SMA_EVENTS_POST_TYPE, 'sma_events_rest_hide_private_meta', 10, 3);

/** Drop private meta from REST responses for anyone without edit_sma_events. */
function sma_events_rest_hide_private_meta($response, $post, $request)
{
    if (current_user_can('edit_sma_events') || !($response instanceof WP_REST_Response)) {
        return $response;
    }
    $data = $response->get_data();
    if (isset($data['meta']) && is_array($data['meta'])) {
        foreach (sma_events_private_meta_keys() as $key) {
            unset($data['meta'][$key]);
        }
        $response->set_data($data);
    }
    return $response;
}

// Bump when the capability grant below changes; the lazy grant then runs once more.
const SMA_EVENTS_CAPS_VERSION = '3';

/** Every primitive capability the sma_event post type uses. */
function sma_events_all_caps()
{
    return [
        'edit_sma_events',
        'edit_others_sma_events',
        'edit_private_sma_events',
        'edit_published_sma_events',
        'publish_sma_events',
        'read_private_sma_events',
        'delete_sma_events',
        'delete_others_sma_events',
        'delete_private_sma_events',
        'delete_published_sma_events',
    ];
}

/** Grant event caps to admins and editors, family access to admins (editors by setting), and the SMA Agent role its exact six caps. */
function sma_events_grant_caps()
{
    foreach (['administrator', 'editor'] as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach (sma_events_all_caps() as $cap) {
                $role->add_cap($cap);
            }
        }
    }
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap(SMA_FAMILIES_CAP);
    }
    sma_settings_sync_editor_cap();
    // Obsolete option from an earlier grant.
    delete_option('sma_families_cap_version');

    $agent_caps = [
        'read'                      => true,
        'edit_sma_events'           => true,
        'edit_others_sma_events'    => true,
        'edit_published_sma_events' => true,
        'publish_sma_events'        => true,
        'read_private_sma_events'   => true,
    ];
    $agent = get_role('sma_agent');
    if (!$agent) {
        add_role('sma_agent', 'SMA Agent', $agent_caps);
    } else {
        // add_role() leaves an existing role untouched, so drop anything else it has collected.
        foreach (array_keys((array) $agent->capabilities) as $cap) {
            if (!isset($agent_caps[$cap])) {
                $agent->remove_cap($cap);
            }
        }
        foreach ($agent_caps as $cap => $grant) {
            $agent->add_cap($cap, $grant);
        }
    }

    // Autoloaded: the lazy grant reads it on every request.
    update_option('sma_caps_version', SMA_EVENTS_CAPS_VERSION, true);
}

function sma_events_activate()
{
    sma_events_grant_caps();
}

// The local site mounts the plugin instead of activating it, so grant once lazily too.
add_action('init', 'sma_events_maybe_grant_caps', 20);

function sma_events_maybe_grant_caps()
{
    if (get_option('sma_caps_version') !== SMA_EVENTS_CAPS_VERSION) {
        sma_events_grant_caps();
        // The current user's caps were computed before the grant; refresh them for this request.
        $user = wp_get_current_user();
        if ($user && $user->ID) {
            $user->get_role_caps();
        }
    }
}

/** The event's time zone: its valid "timezone" meta, else the site's. */
function sma_event_timezone(WP_Post $event)
{
    $name = trim((string) get_post_meta($event->ID, 'timezone', true));
    if ($name !== '') {
        try {
            return new DateTimeZone($name);
        } catch (\Exception $e) {
            // Fall through to the site zone.
        }
    }
    return wp_timezone();
}

/** Today's date (Y-m-d) where the event happens. */
function sma_event_today(WP_Post $event)
{
    return (new DateTimeImmutable('now', sma_event_timezone($event)))->format('Y-m-d');
}

/** True when the event's date is before today in its own zone (no date: never passed). */
function sma_event_is_past(WP_Post $event)
{
    $date = trim((string) get_post_meta($event->ID, 'event_date', true));
    return $date !== '' && $date < sma_event_today($event);
}

/** Published events dated today or later in their own zone, soonest first. */
function sma_events_open()
{
    // Coarse filter in SQL: zones can put "today" up to two calendar days behind the site's.
    $earliest = wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS);

    $events = get_posts([
        'post_type'      => SMA_EVENTS_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'meta_query'     => [[
            'key'     => 'event_date',
            'value'   => $earliest,
            'compare' => '>=',
            'type'    => 'DATE',
        ]],
    ]);

    return array_values(array_filter($events, function ($event) {
        return !sma_event_is_past($event);
    }));
}

/** Dropdown label in the site's style: "Pittsburgh — Sunday, October 4th". */
function sma_events_label(WP_Post $event)
{
    $city = get_post_meta($event->ID, 'city', true);
    $date = get_post_meta($event->ID, 'event_date', true);
    $when = $date ? date_i18n('l, F jS', strtotime($date)) : '';

    return trim($city . ($when ? ' — ' . $when : ''));
}

add_filter('fluentform/rendering_field_data_select', 'sma_events_fill_dropdown', 10, 2);

/** Whether a select field is this form's event dropdown. */
function sma_is_event_field($data, $form)
{
    $name = is_array($data) ? (string) ($data['attributes']['name'] ?? '') : '';
    if ($name === '') {
        return false;
    }
    return in_array($name, sma_event_field_names($form), true);
}

/** Whether an existing dropdown option survives a rebuild: undated options stay, hand-typed dated events go. */
function sma_events_keep_option($option)
{
    $value = (string) ($option['value'] ?? '');
    $label = (string) ($option['label'] ?? $value);
    if ($value === '') {
        $keep = false;
    } elseif (strpos($value, SMA_EVENTS_KEEP_PREFIX) === 0) {
        $keep = true;
    } else {
        $dated = '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+\d|\d{4}-\d{2}-\d{2}/i';
        $keep = !preg_match($dated, $label . ' ' . $value);
    }
    return (bool) apply_filters('sma_events_keep_dropdown_option', $keep, $option);
}

function sma_events_fill_dropdown($data, $form)
{
    if (!sma_is_event_field($data, $form)) {
        return $data;
    }

    $options = [];
    foreach (sma_events_open() as $event) {
        $options[] = [
            'label'      => sma_events_label($event),
            'value'      => $event->post_name,
            'calc_value' => '',
        ];
    }

    foreach ($data['settings']['advanced_options'] ?? [] as $existing) {
        if (sma_events_keep_option($existing)) {
            $options[] = $existing;
        }
    }

    $data['settings']['advanced_options'] = $options;

    return $data;
}

/** The event with this slug, in any status except trash, or null. */
function sma_event_by_slug($slug)
{
    $slug = sanitize_title((string) $slug);
    if ($slug === '') {
        return null;
    }

    $found = get_posts([
        'post_type'      => SMA_EVENTS_POST_TYPE,
        'name'           => $slug,
        'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
    ]);

    return $found ? $found[0] : null;
}

/** Everything an email or page needs to describe an event; no family data. */
function sma_event_details(WP_Post $event)
{
    $meta = function ($key) use ($event) {
        return trim((string) get_post_meta($event->ID, $key, true));
    };

    $date  = $meta('event_date');
    $start = $meta('start_time');
    $end   = $meta('end_time');
    $stamp = $date ? strtotime($date) : false;

    $time_label = '';
    if ($start) {
        $time_label = sma_events_time_label($start);
        if ($end) {
            $time_label .= ' to ' . sma_events_time_label($end);
        }
    }

    $venue   = $meta('venue');
    $address = $meta('address');

    return [
        'slug'            => $event->post_name,
        'city'            => $meta('city'),
        'event_date'      => $date,
        'date_label'      => $stamp ? date_i18n('l, F jS', $stamp) : '',
        'time_label'      => $time_label,
        'venue'           => $venue,
        'address'         => $address,
        'location'        => implode(', ', array_filter([$venue, $address])),
        'canvas_handover' => $meta('canvas_handover'),
        'booking_url'     => esc_url_raw($meta('booking_url')),
        'label'           => sma_events_label($event),
    ];
}

/** "10:00" → "10:00am", "13:30" → "1:30pm". */
function sma_events_time_label($hhmm)
{
    $stamp = strtotime('2000-01-01 ' . $hhmm);
    return $stamp ? date('g:ia', $stamp) : (string) $hhmm;
}

/** "Event: Pittsburgh 2026-11-14", or the slug when city and date are missing. */
function sma_event_tag_title(WP_Post $event)
{
    $city = trim((string) get_post_meta($event->ID, 'city', true));
    $date = trim((string) get_post_meta($event->ID, 'event_date', true));
    $name = trim($city . ' ' . $date);

    return 'Event: ' . ($name !== '' ? $name : $event->post_name);
}

/** Ensure the event's FluentCRM tag exists and is stored in crm_tag_id; returns its id or 0. */
function sma_event_ensure_tag(WP_Post $event)
{
    if ($event->post_name === '') {
        return 0;
    }

    $tag_id = sma_get_or_create_tag(sma_event_tag_slug($event->post_name), sma_event_tag_title($event));
    if ($tag_id && (int) get_post_meta($event->ID, 'crm_tag_id', true) !== $tag_id) {
        update_post_meta($event->ID, 'crm_tag_id', $tag_id);
    }

    return $tag_id;
}

// Provisions after meta is saved (admin and REST); a published event's slug must not change, as it keys its tag and sign-ups.
add_action('wp_after_insert_post', 'sma_event_after_save', 10, 4);

function sma_event_after_save($post_id, $post, $update = false, $post_before = null)
{
    if (!$post instanceof WP_Post || $post->post_type !== SMA_EVENTS_POST_TYPE || $post->post_status !== 'publish') {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    // Booking opens only when the event becomes published, never on a re-save.
    $reopen = !($post_before instanceof WP_Post) || $post_before->post_status !== 'publish';

    // sma_provision_event never throws; this is the last line of defence for the save itself.
    try {
        sma_provision_event($post, $reopen);
    } catch (\Throwable $e) {
        error_log('sma: event set-up failed for event ' . (int) $post_id . ' (' . get_class($e) . ')');
    }
}

// ---------------------------------------------------------------------------- edit screen

// The fields the "Event details" box edits, with their input types.
const SMA_EVENT_DETAIL_FIELDS = [
    'city'            => 'text',
    'venue'           => 'text',
    'address'         => 'text',
    'event_date'      => 'date',
    'start_time'      => 'time',
    'end_time'        => 'time',
    'timezone'        => 'timezone',
    'capacity'        => 'number',
    'canvas_handover' => 'text',
];

add_action('add_meta_boxes_' . SMA_EVENTS_POST_TYPE, 'sma_event_meta_boxes');

function sma_event_meta_boxes($post)
{
    add_meta_box('sma-event-details', 'Event details', 'sma_event_details_box', SMA_EVENTS_POST_TYPE, 'normal', 'high');
    add_meta_box('sma-event-status', 'Set-up status', 'sma_event_status_box', SMA_EVENTS_POST_TYPE, 'side', 'high');
    // REST needs "custom-fields" support; the raw box is replaced by the details box.
    remove_meta_box('postcustom', SMA_EVENTS_POST_TYPE, 'normal');
}

function sma_event_details_box($post)
{
    $values = [];
    foreach (array_keys(SMA_EVENT_DETAIL_FIELDS) as $key) {
        $values[$key] = (string) get_post_meta($post->ID, $key, true);
    }
    if ($values['capacity'] === '0') {
        $values['capacity'] = '';
    }
    $site_zone = wp_timezone_string();
    if ($values['timezone'] === '' && in_array($site_zone, timezone_identifiers_list(), true)) {
        $values['timezone'] = $site_zone;
    }
    sma_include_view(SMA_EVENTS_DIR . '/admin/event-details-box.php', [
        'post'      => $post,
        'values'    => $values,
        'site_zone' => $site_zone,
    ]);
}

function sma_event_status_box($post)
{
    sma_include_view(SMA_EVENTS_DIR . '/admin/event-status-box.php', [
        'post'      => $post,
        'status'    => sma_provision_status($post),
        'session'   => sma_event_session_summary($post),
        'days'      => sma_provision_reminder_days(),
        'tag_id'    => (int) get_post_meta($post->ID, 'crm_tag_id', true),
        'warning'   => sma_provision_warning($post),
        'published' => $post->post_status === 'publish',
    ]);
}

/** "Session 12 (open for booking)" from FluentBooking, when it is active. */
function sma_event_session_summary(WP_Post $event)
{
    $id = (int) get_post_meta($event->ID, 'booking_session_id', true);
    if (!$id) {
        return '';
    }
    $state = '';
    if (sma_provision_booking_active()) {
        try {
            $slot  = \FluentBooking\App\Models\CalendarSlot::find($id);
            $state = !$slot ? 'missing' : ($slot->status === 'active' ? 'open for booking' : 'closed');
        } catch (\Throwable $e) {
            $state = '';
        }
    }
    return 'Session ' . $id . ($state !== '' ? ' (' . $state . ')' : '');
}

// Saves the edit screen's details box (nonce-checked) before provisioning runs.
add_action('save_post_' . SMA_EVENTS_POST_TYPE, 'sma_event_details_save', 10, 2);

function sma_event_details_save($post_id, $post)
{
    if (!isset($_POST['sma_event_details_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sma_event_details_nonce'])), 'sma_event_details_' . $post_id)) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $bad = [];
    foreach (SMA_EVENT_DETAIL_FIELDS as $key => $type) {
        if (!isset($_POST['sma_event'][$key])) {
            continue;
        }
        $value = trim(sanitize_text_field(wp_unslash($_POST['sma_event'][$key])));
        if ($value !== '' && !sma_event_detail_valid($type, $value)) {
            $bad[] = $key;
        }
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } elseif ($type === 'number') {
            update_post_meta($post_id, $key, ctype_digit($value) ? (int) $value : $value);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }
    // Invalid values are kept as typed; provisioning refuses them and the edit screen says why.
    if ($bad) {
        set_transient('sma_event_bad_fields_' . get_current_user_id(), $bad, 60);
    }
}

/** Shape check for one edit-screen field (full rules in sma_provision_input()). */
function sma_event_detail_valid($type, $value)
{
    if ($type === 'date') {
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }
    if ($type === 'time') {
        return sma_provision_hhmm($value) !== '';
    }
    if ($type === 'timezone') {
        return in_array($value, timezone_identifiers_list(), true);
    }
    if ($type === 'number') {
        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= SMA_PROVISION_CAPACITY_MAX;
    }
    return true;
}

add_action('admin_notices', 'sma_event_edit_notices');

/** On an event's edit screen: invalid fields from the last save and any set-up warnings. */
function sma_event_edit_notices()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== SMA_EVENTS_POST_TYPE) {
        return;
    }

    $labels = [
        'city' => 'City', 'venue' => 'Venue', 'address' => 'Address', 'event_date' => 'Date',
        'start_time' => 'Start time', 'end_time' => 'End time', 'timezone' => 'Time zone',
        'capacity' => 'Capacity', 'canvas_handover' => 'Canvas handover',
    ];
    $key = 'sma_event_bad_fields_' . get_current_user_id();
    $bad = get_transient($key);
    if (is_array($bad) && $bad) {
        delete_transient($key);
        $names = array_map(function ($field) use ($labels) {
            return isset($labels[$field]) ? $labels[$field] : $field;
        }, $bad);
        echo '<div class="notice notice-error"><p>' . esc_html('Please check: ' . implode(', ', $names) . '. The event was saved, but booking was not set up.') . '</p></div>';
    }

    $post = get_post();
    if ($post instanceof WP_Post && $post->post_type === SMA_EVENTS_POST_TYPE) {
        $warnings = array_merge([sma_provision_warning($post)], sma_event_booking_warnings($post));
        foreach (array_filter($warnings) as $warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
    }
}
