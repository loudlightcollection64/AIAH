<?php
// CSV export of families and volunteers for Google Sheets (admin download and REST share one generator); allow-listed fields only.

if (!defined('ABSPATH')) {
    exit;
}

function sma_export_family_header()
{
    return [
        'event_slug', 'event_choice', 'event_date', 'city', 'guardian_first_name', 'guardian_last_name', 'email',
        'phone', 'child_first_name', 'age', 'costume_theme', 'flash_sensitive', 'likeness_release',
        'social_media_ok', 'story_ok', 'stage', 'booking_start', 'last_decision', 'registered_at', 'flags',
        'canvas_status', 'canvas_edited_at', 'canvas_printed_at', 'canvas_delivered_at', 'canvas_delivered_how',
    ];
}

function sma_export_volunteer_header()
{
    return [
        'event_slug', 'first_name', 'last_name', 'email', 'phone', 'role', 'preferred_location',
        'portfolio_url', 'stage', 'applied_at',
    ];
}

/** Events for a slug or 'all'; null in the list stands for the no-event group. */
function sma_export_events($which)
{
    if ($which === '' || $which === 'all') {
        $events   = sma_events_published();
        $events[] = null;
        return $events;
    }
    if ($which === SMA_STAGES_NO_EVENT) {
        return [null];
    }
    $event = sma_event_by_slug($which);
    if (!$event) {
        return new WP_Error('sma_no_event', 'Event not found.', ['status' => 404]);
    }
    return [$event];
}

/** Family rows in header order, or WP_Error. */
function sma_export_family_rows($which)
{
    $events = sma_export_events($which);
    if (is_wp_error($events)) {
        return $events;
    }
    $rows = [];
    foreach ($events as $event) {
        $details  = $event ? sma_event_details($event) : ['event_date' => '', 'city' => ''];
        $families = sma_families_for_event($event ? $event->post_name : SMA_STAGES_NO_EVENT);
        foreach ((array) $families as $f) {
            $canvas = is_array($f['canvas']) ? $f['canvas'] : [];
            $rows[] = [
                $f['event_slug'],
                $event ? '' : $f['choice'],
                $details['event_date'],
                $details['city'],
                $f['first_name'],
                $f['last_name'],
                $f['email'],
                $f['phone'],
                $f['child_first_name'],
                $f['age'],
                $f['costume_theme'],
                $f['flash_sensitive'],
                $f['likeness_release'],
                $f['social_media_ok'],
                $f['story_ok'],
                $f['stage'],
                $f['booking_start'],
                $f['decision_label'],
                $f['registered_at'],
                implode(' ', $f['flags']),
                // '' unless attended; then not-started, edited, printed or delivered.
                $canvas ? ($canvas['status'] !== '' ? $canvas['status'] : 'not-started') : '',
                $canvas['edited_at'] ?? '',
                $canvas['printed_at'] ?? '',
                $canvas['delivered_at'] ?? '',
                $canvas['delivered_how'] ?? '',
            ];
        }
    }
    return $rows;
}

/** Volunteer rows in header order, or WP_Error. */
function sma_export_volunteer_rows($which)
{
    $events = sma_export_events($which);
    if (is_wp_error($events)) {
        return $events;
    }
    // 'all' is every volunteer's every per-event entry, including "_none" (no specific date).
    $list = ($which === '' || $which === 'all')
        ? sma_volunteer_event_rows('')
        : sma_volunteer_event_rows($events[0] ? $events[0]->post_name : SMA_STAGES_NO_EVENT);
    $rows = [];
    foreach ($list as $v) {
        $rows[] = [
            $v['event_slug'],
            $v['first_name'],
            $v['last_name'],
            $v['email'],
            $v['phone'],
            $v['role'],
            $v['preferred_location'],
            $v['portfolio_url'],
            $v['stage'],
            $v['applied_at'],
        ];
    }
    return $rows;
}

/** Keep a spreadsheet from reading a cell as a formula. */
function sma_export_cell($value)
{
    $value = (string) $value;
    if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
        return "'" . $value;
    }
    return $value;
}

/** The CSV text: BOM, header, rows, CRLF line ends, fputcsv quoting. */
function sma_export_csv_text(array $header, array $rows)
{
    $out = "\xEF\xBB\xBF";
    $lines = array_merge([$header], $rows);
    foreach ($lines as $i => $line) {
        $cells = $i === 0 ? $line : array_map('sma_export_cell', $line);
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $cells, ',', '"', '\\');
        rewind($fh);
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        // fputcsv ends a record with "\n" on PHP 7.4; Sheets and Excel prefer CRLF.
        if (substr($text, -1) === "\n") {
            $text = substr($text, 0, -1);
        }
        $out .= $text . "\r\n";
    }
    return $out;
}

/** The CSV for 'families' or 'volunteers' and an event slug or 'all': [filename, csv, rows] or WP_Error. */
function sma_export_csv($kind, $which)
{
    $which = ($which === '' || $which === 'all') ? 'all' : sanitize_title($which);

    if ($kind === 'volunteers') {
        $header = sma_export_volunteer_header();
        $rows   = sma_export_volunteer_rows($which);
    } else {
        $kind = 'families';
        if (!sma_crm_ready()) {
            return new WP_Error('sma_crm_missing', 'FluentCRM is not active.', ['status' => 503]);
        }
        $header = sma_export_family_header();
        $rows   = sma_export_family_rows($which);
    }
    if (is_wp_error($rows)) {
        return $rows;
    }

    return [
        'filename' => 'sma-' . $kind . '-' . $which . '-' . wp_date('Y-m-d') . '.csv',
        'csv'      => sma_export_csv_text($header, $rows),
        'rows'     => count($rows),
    ];
}

/* ------------------------------------------------------------------------- admin download */

add_action('admin_post_sma_export', 'sma_export_handle_families');
add_action('admin_post_sma_export_volunteers', 'sma_export_handle_volunteers');

function sma_export_handle_families()
{
    sma_export_download('families');
}

function sma_export_handle_volunteers()
{
    sma_export_download('volunteers');
}

function sma_export_download($kind)
{
    if (!sma_can_manage_families()) {
        wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
    }
    check_admin_referer('sma_export');

    $which  = isset($_GET['event']) ? sanitize_title(wp_unslash($_GET['event'])) : 'all';
    $result = sma_export_csv($kind, $which);
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()), 400);
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($result['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    echo $result['csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, not HTML
    exit;
}

/* ------------------------------------------------------------------------------------ REST */

add_action('rest_api_init', 'sma_export_rest_register');

function sma_export_rest_register()
{
    $args = [
        'event' => [
            'required'          => false,
            'type'              => 'string',
            'default'           => 'all',
            'sanitize_callback' => 'sanitize_title',
        ],
    ];
    foreach (['families', 'volunteers'] as $kind) {
        register_rest_route('sma/v1', '/export/' . $kind, [
            'methods'             => 'GET',
            'permission_callback' => 'sma_can_manage_families',
            'args'                => $args,
            'callback'            => function (WP_REST_Request $request) use ($kind) {
                $result = sma_export_csv($kind, (string) $request['event']);
                if (is_wp_error($result)) {
                    return $result;
                }
                $response = new WP_REST_Response($result['csv'], 200);
                $response->header('Content-Type', 'text/csv; charset=utf-8');
                $response->header('Content-Disposition', 'attachment; filename="' . sanitize_file_name($result['filename']) . '"');
                $response->header('X-SMA-Rows', (string) $result['rows']);
                return $response;
            },
        ]);
    }
}

// Serve the CSV routes' string body as-is instead of JSON-encoding it.
add_filter('rest_pre_serve_request', 'sma_export_rest_serve', 10, 3);

function sma_export_rest_serve($served, $result, $request)
{
    if ($served || !($request instanceof WP_REST_Request) || !($result instanceof WP_HTTP_Response)) {
        return $served;
    }
    if (strpos((string) $request->get_route(), '/sma/v1/export/') !== 0) {
        return $served;
    }
    if ($result->get_status() !== 200 || !is_string($result->get_data())) {
        return $served;
    }
    echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, not HTML
    return true;
}
