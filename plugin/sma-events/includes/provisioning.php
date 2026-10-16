<?php
// On publish, sets up the event's FluentBooking session, its emails, a booking page and the CRM tag, idempotently, without user capabilities.

if (!defined('ABSPATH')) {
    exit;
}

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\SanitizeService;

const SMA_PROVISION_CAPACITY_MAX = 500;

// Page meta linking a plugin-managed booking page back to its event (protected: leading "_").
const SMA_BOOKING_PAGE_META = '_sma_booking_page_event';

// FluentBooking session meta naming the sma_event that made the session.
const SMA_SESSION_OWNER_META = '_sma_event_id';

/** Whether the FluentBooking classes this file uses are loaded. */
function sma_provision_booking_active()
{
    return class_exists('\FluentBooking\App\Models\Calendar')
        && class_exists('\FluentBooking\App\Models\CalendarSlot')
        && class_exists('\FluentBooking\App\Services\SanitizeService');
}

/** Set up everything a published event needs; failures go to sma_provision_error ($reopen: the event was just published). */
function sma_provision_event(WP_Post $event, $reopen = false)
{
    $errors = [];

    try {
        if (!sma_event_ensure_tag($event)) {
            $errors[] = 'FluentCRM is not active, so this event has no CRM tag and sign-ups can\'t be grouped under it. Activate FluentCRM and save the event again.';
        }
    } catch (\Throwable $e) {
        sma_provision_log($event, 'tag', $e);
        $errors[] = 'The event\'s CRM tag couldn\'t be made. Save the event again to retry.';
    }

    $created = false;
    $slot    = sma_provision_booking($event, $errors, $reopen, $created);

    if ($slot) {
        try {
            $notified = sma_provision_notifications($slot, $reopen || $created);
            if (is_wp_error($notified)) {
                $errors[] = $notified->get_error_message();
            }
        } catch (\Throwable $e) {
            sma_provision_log($event, 'notifications', $e);
            $errors[] = 'The booking session is ready, but its confirmation and reminder emails couldn\'t be set up. Save the event again to retry.';
        }

        try {
            $url = sma_provision_booking_page($event, (int) $slot->id, $reopen || $created);
            if (is_wp_error($url)) {
                $errors[] = $url->get_error_message();
            } elseif ($url !== '' && get_post_meta($event->ID, 'booking_url', true) !== $url) {
                update_post_meta($event->ID, 'booking_url', esc_url_raw($url));
            }
        } catch (\Throwable $e) {
            sma_provision_log($event, 'page', $e);
            $errors[] = 'The booking session is ready, but its booking page couldn\'t be made, so approved families have no link yet. Save the event again to retry.';
        }
    }

    if ($errors) {
        update_post_meta($event->ID, 'sma_provision_error', implode(' ', array_unique($errors)));
        return false;
    }

    delete_post_meta($event->ID, 'sma_provision_error');
    update_post_meta($event->ID, 'sma_provisioned_at', current_time('mysql'));
    return true;
}

/** Validate, pick the calendar and save the session; returns it or null, adding sentences to $errors. */
function sma_provision_booking(WP_Post $event, array &$errors, $reopen = false, &$created = false)
{
    if (!sma_provision_booking_active()) {
        $errors[] = 'FluentBooking is not active, so no booking sessions were made. Activate it and save the event again.';
        return null;
    }

    $input = sma_provision_input($event);
    if (is_wp_error($input)) {
        $errors[] = $input->get_error_message();
        return null;
    }

    try {
        $calendar = sma_provision_calendar($event);
        if (is_wp_error($calendar)) {
            $errors[] = $calendar->get_error_message();
            return null;
        }
        $slot = sma_provision_slot($event, $calendar, $input, $reopen, $created);
    } catch (\Throwable $e) {
        sma_provision_log($event, 'session', $e);
        $errors[] = 'FluentBooking couldn\'t save the booking session, so families can\'t book yet. Save the event again; if this keeps happening, check FluentBooking.';
        return null;
    }

    // Bookings made for an earlier date or time stay where they are and are flagged.
    if (!$created) {
        try {
            sma_booking_window_check($event, $slot, $input);
        } catch (\Throwable $e) {
            sma_provision_log($event, 'window check', $e);
        }
    }

    if ((int) get_post_meta($event->ID, 'booking_calendar_id', true) !== (int) $calendar->id) {
        update_post_meta($event->ID, 'booking_calendar_id', (int) $calendar->id);
    }
    if ((int) get_post_meta($event->ID, 'booking_session_id', true) !== (int) $slot->id) {
        update_post_meta($event->ID, 'booking_session_id', (int) $slot->id);
    }
    return $slot;
}

/** Read and check the event's fields (times HH:MM in the event's zone; capacity 0 = no cap), or WP_Error. */
function sma_provision_input(WP_Post $event)
{
    $meta = function ($key) use ($event) {
        return trim((string) get_post_meta($event->ID, $key, true));
    };
    $fail = function ($message) {
        return new WP_Error('sma_provision_input', $message);
    };

    $date = $meta('event_date');
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return $fail('The event date is missing or not a real date, so no booking sessions were made.');
    }

    $start = sma_provision_hhmm($meta('start_time'));
    $end   = sma_provision_hhmm($meta('end_time'));
    if ($start === '' || $end === '') {
        return $fail('The start time or end time is missing, so no booking sessions were made.');
    }
    if ($end <= $start) {
        return $fail('The end time is before the start time, so no booking sessions were made.');
    }

    $minutes = max(5, (int) sma_setting('session_minutes')); // FluentBooking's own minimum is 5 (CalendarController.php:465)
    if (sma_provision_minutes($end) - sma_provision_minutes($start) < $minutes) {
        return $fail('The time window is shorter than one ' . $minutes . '-minute session, so no booking sessions were made.');
    }

    $zone = $meta('timezone');
    if ($zone === '') {
        $zone = wp_timezone_string();
    } elseif (!in_array($zone, timezone_identifiers_list(), true)) {
        return $fail('The time zone isn\'t one WordPress knows, so no booking sessions were made. Pick one from the list.');
    }

    // Empty or 0 (an unset integer meta reads back as 0 over REST): no per-day cap.
    $capacity = $meta('capacity');
    if ($capacity === '0') {
        $capacity = '';
    }
    if ($capacity !== '' && (!ctype_digit($capacity) || (int) $capacity < 1 || (int) $capacity > SMA_PROVISION_CAPACITY_MAX)) {
        return $fail('The capacity must be a number from 1 to ' . SMA_PROVISION_CAPACITY_MAX . ', so no booking sessions were made.');
    }

    $details = sma_event_details($event);
    $city    = $details['city'] !== '' ? $details['city'] : $event->post_title;

    return [
        'date'     => $date,
        'start'    => $start,
        'end'      => $end,
        'timezone' => $zone,
        'capacity' => (int) $capacity,
        'minutes'  => $minutes,
        'venue'    => $details['venue'] !== '' ? $details['venue'] : $city,
        'address'  => $details['address'],
        'title'    => 'Photo sessions: ' . $city . ($details['date_label'] !== '' ? ', ' . $details['date_label'] : ''),
    ];
}

/** "9:05", "09:05" or "09:05:00" → "09:05"; anything else → ''. */
function sma_provision_hhmm($text)
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', (string) $text, $m)) {
        return '';
    }
    return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
}

function sma_provision_minutes($hhmm)
{
    list($h, $m) = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

/** The session's calendar: Settings' choice, else the event's current one, else the first host calendar, else the first. */
function sma_provision_calendar(WP_Post $event)
{
    $wanted = (int) sma_setting('booking_calendar_id');
    if ($wanted) {
        $calendar = Calendar::find($wanted); // Eloquent-style find, as BlockEditorHandler.php:243
        if (!$calendar) {
            return new WP_Error('sma_provision_calendar', 'The booking calendar chosen in So Many Angels → Settings no longer exists, so no booking sessions were made. Choose another one there, then save the event again.');
        }
        return $calendar;
    }

    $stored = (int) get_post_meta($event->ID, 'booking_calendar_id', true);
    $calendar = $stored ? Calendar::find($stored) : null;
    if (!$calendar) {
        // "simple" is a host's own calendar.
        $calendar = Calendar::where('type', 'simple')->orderBy('id', 'asc')->first();
    }
    if (!$calendar) {
        $calendar = Calendar::orderBy('id', 'asc')->first();
    }
    if (!$calendar) {
        return new WP_Error('sma_provision_calendar', 'FluentBooking has no calendar yet, so no booking sessions were made. Create one in FluentBooking, then save the event again.');
    }
    return $calendar;
}

/** The event's window as date overrides in the calendar's zone, converted on the event date so daylight saving is right. */
function sma_provision_window(array $input, $calendar_zone)
{
    $from  = new DateTimeZone($input['timezone']);
    $to    = new DateTimeZone($calendar_zone);
    $start = (new DateTimeImmutable($input['date'] . ' ' . $input['start'], $from))->setTimezone($to);
    $end   = (new DateTimeImmutable($input['date'] . ' ' . $input['end'], $from))->setTimezone($to);

    $first = $start->format('Y-m-d');
    $last  = $end->format('Y-m-d');

    if ($first === $last) {
        $overrides = [$first => [['start' => $start->format('H:i'), 'end' => $end->format('H:i')]]];
    } else {
        // The window crosses midnight in the calendar's zone (venue far from the calendar owner).
        $overrides = [$first => [['start' => $start->format('H:i'), 'end' => '23:59']]];
        if ($end->format('H:i') !== '00:00') {
            $overrides[$last] = [['start' => '00:00', 'end' => $end->format('H:i')]];
        } else {
            $last = $first;
        }
    }

    return ['overrides' => $overrides, 'first' => $first, 'last' => $last];
}

/** The calendar's zone, or UTC when it has none or an invalid one. */
function sma_provision_calendar_zone($calendar)
{
    $zone = (string) $calendar->author_timezone; // fillable column, Calendar.php:31
    if ($zone === '') {
        return 'UTC';
    }
    try {
        new DateTimeZone($zone);
    } catch (\Exception $e) {
        return 'UTC';
    }
    return $zone;
}

/** Create or update the event's session type the way FluentBooking's CalendarController does. */
function sma_provision_slot(WP_Post $event, $calendar, array $input, $reopen = false, &$created = false)
{
    $zone   = sma_provision_calendar_zone($calendar);
    $window = sma_provision_window($input, $zone);

    // Closed every weekday: the event date's override is the only bookable time.
    $closed = [];
    foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) { // keys as Helper::getWeeklyScheduleSchema(), Helper.php:1557
        $closed[$day] = ['enabled' => false, 'slots' => []];
    }

    $capacity = (int) $input['capacity'];
    $settings = [
        'schedule_type'      => 'weekly_schedules',
        'weekly_schedules'   => SanitizeService::weeklySchedules($closed, $zone, 'UTC', true),             // SanitizeService.php:9
        'date_overrides'     => SanitizeService::slotDateOverrides($window['overrides'], $zone, 'UTC', null, true), // :56
        'range_type'         => 'range_date_between',
        'range_days'         => 60,
        'range_date_between' => SanitizeService::rangeDateBetween([$window['first'], $window['last']]),  // :121
        // Per-day cap; booking_duration is set too because the admin screen resets both if either is missing.
        'booking_frequency'  => [
            'enabled' => $capacity > 0,
            'limits'  => [['unit' => 'per_day', 'value' => $capacity > 0 ? $capacity : 5]],
        ],
        'booking_duration'   => [
            'enabled' => false,
            'limits'  => [['unit' => 'per_day', 'value' => 120]],
        ],
        'lock_timezone'      => [
            'enabled'  => in_array($input['timezone'], timezone_identifiers_list(), true),
            'timezone' => $input['timezone'],
        ],
    ];

    $location = SanitizeService::locationSettings([[ // SanitizeService.php:159
        'type'        => 'in_person_organizer',
        'title'       => $input['venue'],
        'description' => $input['address'],
    ]]);

    // Only a session this event made; anything else the stored id names is left alone.
    $slot = sma_provision_owned_slot($event, (int) get_post_meta($event->ID, 'booking_session_id', true));
    if ($slot) {
        if ((int) $slot->calendar_id !== (int) $calendar->id) {
            // Settings now name another calendar: close the old session, make a new one there.
            $slot->status = 'draft';
            $slot->save();
            $slot = null;
        }
    }

    if (!$slot) {
        // Same fields as FluentBooking's createCalendarEvent, with custom availability.
        $settings['schedule_conditions'] = SanitizeService::scheduleConditions([]); // :136, FluentBooking's default notice
        $settings['buffer_time_before']  = '0';
        $settings['buffer_time_after']   = '0';
        $settings['slot_interval']       = '';
        $settings['team_members']        = [];

        $slot = CalendarSlot::create([
            'title'             => sanitize_text_field($input['title']),
            'slug'              => \FluentBooking\App\Services\Helper::generateSlotSlug('sma-' . $event->post_name, $calendar), // Helper.php:939
            'calendar_id'       => (int) $calendar->id,
            'user_id'           => (int) $calendar->user_id, // else boot() uses the current user (CalendarSlot.php:51)
            'duration'          => (int) $input['minutes'],
            'description'       => '',
            'settings'          => $settings,
            'status'            => 'active',
            'color_schema'      => '#0099ff',
            'event_type'        => 'single',
            'availability_type' => 'custom',
            'availability_id'   => 0,
            'location_type'     => 'in_person_organizer',
            'location_settings' => $location,
            'max_book_per_slot' => 1,
            'is_display_spots'  => false,
        ]);
        $created = true;
        // Store the id and owner mark at once so a later failure can't create a second session.
        update_post_meta($event->ID, 'booking_session_id', (int) $slot->id);
        update_post_meta($event->ID, 'booking_calendar_id', (int) $calendar->id);
        $slot->updateMeta(SMA_SESSION_OWNER_META, (int) $event->ID); // CalendarSlot.php:694
        try {
            $calendar->updateEventOrder($slot->id); // Calendar.php:105, as the controller does
        } catch (\Throwable $e) {
            sma_provision_log($event, 'event order', $e); // cosmetic: order in FluentBooking's list
        }
        return $slot;
    }

    $slot->title             = sanitize_text_field($input['title']);
    $slot->duration          = (int) $input['minutes'];
    if ($reopen) {
        // Only on publish, so a session closed by hand stays closed through re-saves.
        $slot->status = 'active';
    }
    $slot->event_type        = 'single';
    $slot->availability_type = 'custom';
    $slot->location_type     = 'in_person_organizer';
    $slot->location_settings = $location;
    $slot->max_book_per_slot = 1;
    // Merges into the stored settings, so other settings (including manual ones) stay.
    $slot->settings = $settings;
    $slot->save();

    return $slot;
}

/** The session with this id if this event owns it (owner meta, or an unmarked "sma-<slug>" session), else null. */
function sma_provision_owned_slot(WP_Post $event, $slot_id)
{
    $slot_id = (int) $slot_id;
    if ($slot_id <= 0) {
        return null;
    }
    $slot = CalendarSlot::find($slot_id);
    if (!$slot) {
        return null;
    }
    $owner = (int) $slot->getMeta(SMA_SESSION_OWNER_META, 0); // CalendarSlot.php:680
    if ($owner === (int) $event->ID) {
        return $slot;
    }
    if ($owner === 0) {
        $base = sanitize_title('sma-' . $event->post_name);
        $slug = (string) $slot->slug;
        if ($event->post_name !== '' && ($slug === $base || preg_match('/^' . preg_quote($base, '/') . '-\d+$/', $slug))) {
            $slot->updateMeta(SMA_SESSION_OWNER_META, (int) $event->ID); // CalendarSlot.php:694
            return $slot;
        }
    }
    sma_provision_log($event, 'session ' . $slot_id . ' not owned by this event', null);
    return null;
}

/** Turn on the attendee confirmation and reminders; wording only when $full, so manual edits survive re-saves. */
function sma_provision_notifications($slot, $full = true)
{
    if (!$full) {
        $notifications = $slot->getNotifications(); // CalendarSlot.php:375
        $times = [];
        foreach (sma_provision_reminder_days() as $days) {
            $times[] = ['unit' => 'days', 'value' => $days];
        }
        if (isset($notifications['reminder_to_attendee']['email']) && is_array($notifications['reminder_to_attendee']['email'])
            && ($notifications['reminder_to_attendee']['email']['times'] ?? null) != $times) {
            $notifications['reminder_to_attendee']['email']['times'] = $times;
            $slot->setNotifications($notifications); // CalendarSlot.php:419
        }
        return true;
    }

    $confirm  = sma_email_template_raw('booking-confirmation');
    $reminder = sma_email_template_raw('booking-reminder');
    if (is_wp_error($confirm) || is_wp_error($reminder)) {
        return new WP_Error('sma_provision_emails', 'The booking session is ready, but the plugin\'s booking email templates are missing, so FluentBooking\'s own emails are used. Reinstall the plugin, then save the event again.');
    }

    $times = [];
    foreach (sma_provision_reminder_days() as $days) {
        $times[] = ['unit' => 'days', 'value' => $days]; // NotificationHandler::getReminderTime, :24
    }

    $notifications = $slot->getNotifications(); // stored, else defaults (CalendarSlot.php:375)

    $notifications['booking_conf_attendee']['enabled']          = true;
    $notifications['booking_conf_attendee']['email']['subject'] = $confirm['subject'];
    $notifications['booking_conf_attendee']['email']['body']    = $confirm['body'];

    $notifications['reminder_to_attendee']['enabled']          = (bool) $times;
    $notifications['reminder_to_attendee']['email']['subject'] = $reminder['subject'];
    $notifications['reminder_to_attendee']['email']['body']    = $reminder['body'];
    $notifications['reminder_to_attendee']['email']['times']   = $times;

    $slot->setNotifications($notifications);
    return true;
}

/** Reminder days from Settings: positive whole numbers, largest first, no repeats. */
function sma_provision_reminder_days()
{
    $days = array_filter(array_map('intval', (array) sma_setting('reminder_days')), function ($d) {
        return $d > 0;
    });
    $days = array_values(array_unique($days));
    rsort($days);
    return $days;
}

/** Create or update this event's own booking page ([fluent_booking id=...]); returns its permalink or WP_Error. */
function sma_provision_booking_page(WP_Post $event, $slot_id, $publish = true)
{
    $details = sma_event_details($event);
    $city    = $details['city'] !== '' ? $details['city'] : $event->post_title;
    $content = '[fluent_booking id="' . (int) $slot_id . '"]';
    $title   = 'Book your session: ' . $city;

    $page = sma_provision_owned_page($event);

    if ($page) {
        $status = $publish ? 'publish' : $page->post_status;
        if ($page->post_status !== $status || $page->post_content !== $content || $page->post_title !== $title) {
            $saved = wp_update_post(wp_slash([
                'ID'           => $page->ID,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
            ]), true);
            if (is_wp_error($saved)) {
                sma_provision_log($event, 'page update ' . $saved->get_error_code(), null);
                return new WP_Error('sma_provision_page', 'The booking session is ready, but its booking page couldn\'t be updated. Save the event again to retry.');
            }
        }
        return (string) get_permalink($page->ID);
    }

    $new_id = wp_insert_post(wp_slash([
        'post_type'      => 'page',
        'post_title'     => $title,
        'post_name'      => 'book-' . $event->post_name,
        'post_content'   => $content,
        'post_status'    => 'publish',
        'post_author'    => (int) $event->post_author,
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
        'meta_input'     => [SMA_BOOKING_PAGE_META => (int) $event->ID],
    ]), true);
    if (is_wp_error($new_id) || !$new_id) {
        sma_provision_log($event, 'page insert ' . (is_wp_error($new_id) ? $new_id->get_error_code() : '0'), null);
        return new WP_Error('sma_provision_page', 'The booking session is ready, but its booking page couldn\'t be made, so approved families have no link yet. Save the event again to retry.');
    }

    update_post_meta($event->ID, 'booking_page_id', (int) $new_id);
    return (string) get_permalink($new_id);
}

/** The event's booking page if this event owns it and it isn't trashed, else null. */
function sma_provision_owned_page(WP_Post $event)
{
    $page_id = (int) get_post_meta($event->ID, 'booking_page_id', true);
    $page    = $page_id ? get_post($page_id) : null;
    if (!$page || $page->post_type !== 'page' || $page->post_status === 'trash') {
        return null;
    }
    if ((int) get_post_meta($page->ID, SMA_BOOKING_PAGE_META, true) !== (int) $event->ID) {
        sma_provision_log($event, 'page ' . (int) $page->ID . ' not owned by this event', null);
        return null;
    }
    return $page;
}

// Leaving "publish" closes the session and booking page; publishing again reopens both.
add_action('transition_post_status', 'sma_event_on_status_change', 10, 3);

function sma_event_on_status_change($new_status, $old_status, $post)
{
    if (!$post instanceof WP_Post || $post->post_type !== SMA_EVENTS_POST_TYPE) {
        return;
    }
    if ($old_status !== 'publish' || $new_status === 'publish') {
        return;
    }
    sma_unprovision_event($post);
}

/** Draft the event's own session and page and pause existing bookings' reminders; bookings are never cancelled here. */
function sma_unprovision_event(WP_Post $event)
{
    if (sma_provision_booking_active()) {
        try {
            $slot = sma_provision_owned_slot($event, (int) get_post_meta($event->ID, 'booking_session_id', true));
            if ($slot && $slot->status !== 'draft') {
                // A draft session refuses new bookings.
                $slot->status = 'draft';
                $slot->save();
            }
        } catch (\Throwable $e) {
            sma_provision_log($event, 'session draft', $e);
        }

        try {
            $held = [];
            foreach (sma_event_upcoming_bookings($event) as $booking) {
                sma_booking_pause_reminders((int) $booking->id);
                $held[(int) $booking->id] = (string) $booking->start_time;
            }
            if ($held) {
                sma_booking_alert_add($event, 'unpublished', $held);
            }
        } catch (\Throwable $e) {
            sma_provision_log($event, 'pause reminders', $e);
        }
    }

    $page = sma_provision_owned_page($event);
    if ($page && $page->post_status === 'publish') {
        $saved = wp_update_post(['ID' => $page->ID, 'post_status' => 'draft'], true);
        if (is_wp_error($saved)) {
            sma_provision_log($event, 'page draft ' . $saved->get_error_code(), null);
        }
    }
}

/* ---------------------------------- bookings that outlive a change to their event ---------------------------------- */

// Event meta: kind ("unpublished", "moved") => {bookings: {booking id => UTC start}, at}; ids and times only.
const SMA_BOOKING_ALERTS_META = '_sma_booking_alerts';

/** Scheduled future bookings on the event's own session. */
function sma_event_upcoming_bookings(WP_Post $event)
{
    $slot_id = (int) get_post_meta($event->ID, 'booking_session_id', true);
    if (!$slot_id || !class_exists('\FluentBooking\App\Models\Booking')) {
        return [];
    }
    $out = [];
    $rows = Booking::where('event_id', $slot_id)
        ->where('status', 'scheduled')
        ->where('start_time', '>', gmdate('Y-m-d H:i:s'))
        ->get();
    foreach ($rows as $row) {
        $out[] = $row;
    }
    return $out;
}

/** Remove a booking's pending reminders; the id is tried as int and string because args match exactly. */
function sma_booking_pause_reminders($booking_id)
{
    $booking_id = (int) $booking_id;
    if ($booking_id <= 0 || !function_exists('as_unschedule_all_actions')) {
        return;
    }
    foreach (['guest', 'host'] as $to) {
        foreach ([$booking_id, (string) $booking_id] as $id) {
            as_unschedule_all_actions('fluent_booking/booking_schedule_reminder', [$id, $to], 'fluent-booking');
        }
    }
}

/** Record bookings (id => UTC start) under an alert kind, merged with any already recorded. */
function sma_booking_alert_add(WP_Post $event, $kind, array $bookings)
{
    $alerts = get_post_meta($event->ID, SMA_BOOKING_ALERTS_META, true);
    if (!is_array($alerts)) {
        $alerts = [];
    }
    $old = isset($alerts[$kind]['bookings']) && is_array($alerts[$kind]['bookings']) ? $alerts[$kind]['bookings'] : [];
    $alerts[$kind] = ['bookings' => $bookings + $old, 'at' => current_time('mysql')];
    update_post_meta($event->ID, SMA_BOOKING_ALERTS_META, $alerts);
}

/** Alert kinds still in force: kind => families whose booking is unchanged; spent alerts are dropped. */
function sma_booking_alerts_live(WP_Post $event)
{
    $alerts = get_post_meta($event->ID, SMA_BOOKING_ALERTS_META, true);
    if (!is_array($alerts) || !$alerts) {
        return [];
    }
    if (sma_event_is_past($event) || !class_exists('\FluentBooking\App\Models\Booking')) {
        return [];
    }

    $live    = [];
    $changed = false;
    foreach ($alerts as $kind => $alert) {
        $recorded = isset($alert['bookings']) && is_array($alert['bookings']) ? $alert['bookings'] : [];
        $emails   = [];
        $still    = [];
        if ($recorded) {
            foreach (Booking::whereIn('id', array_map('intval', array_keys($recorded)))->get() as $booking) {
                $id = (int) $booking->id;
                if ($booking->status === 'scheduled' && isset($recorded[$id]) && (string) $booking->start_time === (string) $recorded[$id]) {
                    $still[$id] = $recorded[$id];
                    $emails[strtolower(trim((string) $booking->email))] = true;
                }
            }
        }
        if (!$still) {
            unset($alerts[$kind]);
            $changed = true;
            continue;
        }
        if (count($still) !== count($recorded)) {
            $alerts[$kind]['bookings'] = $still;
            $changed = true;
        }
        $live[$kind] = count($emails);
    }
    if ($changed) {
        if ($alerts) {
            update_post_meta($event->ID, SMA_BOOKING_ALERTS_META, $alerts);
        } else {
            delete_post_meta($event->ID, SMA_BOOKING_ALERTS_META);
        }
    }
    return $live;
}

/** Plain sentences about bookings this event's changes left behind, for the dashboard and edit screen. */
function sma_event_booking_warnings(WP_Post $event)
{
    try {
        $live = sma_booking_alerts_live($event);
    } catch (\Throwable $e) {
        sma_provision_log($event, 'booking alerts', $e);
        return [];
    }
    $families = function ($n) {
        return $n === 1 ? '1 family still holds a booking' : $n . ' families still hold bookings';
    };

    $out = [];
    if (isset($live['unpublished'])) {
        if ($event->post_status !== 'publish') {
            $out[] = 'Event unpublished: ' . $families($live['unpublished']) . '; reminders paused. Their bookings were not cancelled. '
                . 'Publish the event again, or decline each family on the dashboard (that cancels the booking, and FluentBooking emails them).';
        } else {
            $out[] = 'Event published again, but ' . $families($live['unpublished']) . ' from before it was unpublished, with no reminders. '
                . 'FluentBooking can\'t restart them on its own (rescheduling a booking in FluentBooking does), so please remind these families yourself.';
        }
    }
    if (isset($live['moved'])) {
        $out[] = 'Date or time changed: ' . $families($live['moved']) . ' at the old time. They are marked needs follow-up and their reminders are paused. '
            . 'Their bookings were not moved: reschedule each one in FluentBooking, or contact the family.';
    }
    return $out;
}

/** Events (any status, trash included) that have booking alerts recorded. */
function sma_events_with_booking_alerts()
{
    return get_posts([
        'post_type'      => SMA_EVENTS_POST_TYPE,
        'post_status'    => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
        'posts_per_page' => -1,
        'meta_key'       => SMA_BOOKING_ALERTS_META,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
}

/** Bookings outside the event's new window: pause reminders, flag the family for follow-up, record a "moved" alert. */
function sma_booking_window_check(WP_Post $event, $slot, array $input)
{
    if (!class_exists('\FluentBooking\App\Models\Booking')) {
        return;
    }
    $utc  = new DateTimeZone('UTC');
    $zone = new DateTimeZone($input['timezone']);
    $from = (new DateTimeImmutable($input['date'] . ' ' . $input['start'], $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
    $to   = (new DateTimeImmutable($input['date'] . ' ' . $input['end'], $zone))->setTimezone($utc)->format('Y-m-d H:i:s');

    $alerts = get_post_meta($event->ID, SMA_BOOKING_ALERTS_META, true);
    $known  = is_array($alerts) && isset($alerts['moved']['bookings']) && is_array($alerts['moved']['bookings'])
        ? $alerts['moved']['bookings'] : [];

    // Booking start_time / end_time are UTC 'Y-m-d H:i:s'.
    $moved = [];
    foreach (Booking::where('event_id', (int) $slot->id)->where('status', 'scheduled')->get() as $booking) {
        $start = (string) $booking->start_time;
        if ($start >= $from && (string) $booking->end_time <= $to) {
            continue;
        }
        $id = (int) $booking->id;
        if (isset($known[$id]) && (string) $known[$id] === $start) {
            continue; // flagged on an earlier save
        }
        sma_booking_pause_reminders($id);
        sma_booking_flag_moved($event, $booking);
        $moved[$id] = $start;
    }
    if ($moved) {
        sma_booking_alert_add($event, 'moved', $moved);
    }
}

/** Mark a booked or approved family left at the old time needs-follow-up, with a note. */
function sma_booking_flag_moved(WP_Post $event, $booking)
{
    if (!function_exists('sma_crm_ready') || !sma_crm_ready()) {
        return;
    }
    $contact = sma_booking_contact($booking); // approval.php
    if (!$contact) {
        return;
    }
    $slug   = $event->post_name;
    $id     = (int) $booking->id;
    $reason = 'Event date or time changed; booking #' . $id . ' is still at the old time. Reminders paused.';
    if (sma_stage_is_confirmed(sma_event_stage($contact, $slug))) {
        sma_set_stage($contact, 'needs-follow-up', $slug);
        sma_record_decision($contact->id, $slug, 'needs-follow-up', $reason);
    }
    sma_add_contact_note($contact->id, 'Event time changed', $reason . ' The booking was not moved: reschedule it in FluentBooking, or contact the family.');
}

/** Set-up status for the dashboard and edit screen: ok, error, booking_url, provisioned_at. */
function sma_provision_status(WP_Post $event)
{
    $error = trim((string) get_post_meta($event->ID, 'sma_provision_error', true));
    $at    = trim((string) get_post_meta($event->ID, 'sma_provisioned_at', true));

    return [
        'ok'             => $error === '' && $at !== '',
        'error'          => $error,
        'booking_url'    => esc_url_raw(trim((string) get_post_meta($event->ID, 'booking_url', true))),
        'provisioned_at' => $at,
    ];
}

/** One line about a published event whose set-up isn't complete, or ''. */
function sma_provision_warning(WP_Post $event)
{
    if ($event->post_status !== 'publish' || sma_event_is_past($event)) {
        return '';
    }
    $status = sma_provision_status($event);
    if ($status['ok']) {
        return '';
    }
    if ($status['error'] !== '') {
        return $status['error'];
    }
    return 'Booking isn\'t set up for this event yet. Open the event and click Update to set it up.';
}

/** Log ids and error classes only, never values. */
function sma_provision_log(WP_Post $event, $step, $e)
{
    error_log('sma: provisioning step "' . sanitize_key(str_replace(' ', '_', (string) $step)) . '" failed for event '
        . (int) $event->ID . ($e ? ' (' . get_class($e) . ')' : ''));
}

// ---------------- booking pages are public via the email link but not indexed or listed ----------------

/** Ids of the plugin-managed booking pages. */
function sma_booking_page_ids()
{
    static $ids = null;
    if ($ids === null) {
        $ids = array_map('intval', get_posts([
            'post_type'        => 'page',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'meta_key'         => SMA_BOOKING_PAGE_META,
            'suppress_filters' => true,
        ]));
    }
    return $ids;
}

add_filter('wp_robots', 'sma_booking_page_robots');

function sma_booking_page_robots($robots)
{
    if (is_page() && in_array((int) get_queried_object_id(), sma_booking_page_ids(), true)) {
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
    }
    return $robots;
}

add_filter('wp_sitemaps_posts_query_args', 'sma_booking_page_sitemap', 10, 2);

function sma_booking_page_sitemap($args, $post_type)
{
    if ($post_type === 'page' && sma_booking_page_ids()) {
        $args['post__not_in'] = array_merge(isset($args['post__not_in']) ? (array) $args['post__not_in'] : [], sma_booking_page_ids());
    }
    return $args;
}

add_filter('wp_list_pages_excludes', 'sma_booking_page_list_excludes');

function sma_booking_page_list_excludes($exclude)
{
    return array_merge((array) $exclude, sma_booking_page_ids());
}

add_filter('rest_page_query', 'sma_booking_page_rest_query');

/** Leave booking pages out of /wp/v2/pages listings for anyone who can't edit pages. */
function sma_booking_page_rest_query($args)
{
    if (!current_user_can('edit_pages') && sma_booking_page_ids()) {
        $args['post__not_in'] = array_merge(isset($args['post__not_in']) ? (array) $args['post__not_in'] : [], sma_booking_page_ids());
    }
    return $args;
}
