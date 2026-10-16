<?php
// Public event pages: /event/<slug>/ and, when the site has no /events/ page, the list at /events/ (docs/design/public-urls.md).

if (!defined('ABSPATH')) {
    exit;
}

// Singles live under /event/ so they can't shadow child pages of a real /events/ page.
const SMA_EVENT_SINGLE_SLUG = 'event';
const SMA_EVENTS_ARCHIVE_SLUG = 'events';

// Part of the stored rewrite key. Bump when the rules this file registers change shape.
const SMA_PAGES_REWRITE_VERSION = '2';

// Sponsor button target, as on the live event pages (filter "sma_givebutter_url").
if (!defined('SMA_GIVEBUTTER_URL')) {
    define('SMA_GIVEBUTTER_URL', 'https://givebutter.com/9Eh6IC');
}

// Costume help link from the live sign-up page (filter "sma_costume_help_url").
if (!defined('SMA_COSTUME_HELP_URL')) {
    define('SMA_COSTUME_HELP_URL', 'https://docs.google.com/forms/d/e/1FAIpQLSfkRB-32OMG5Jr1unvC6pnNz9T1kZ_95px0r75MYOcxYCF32w/viewform');
}

/* ------------------------------------------------------------------ routing */

// Sets the single-event slug and the archive while the post type registers.
add_filter('register_post_type_args', 'sma_pages_post_type_args', 10, 2);

function sma_pages_post_type_args($args, $post_type)
{
    if ($post_type !== SMA_EVENTS_POST_TYPE) {
        return $args;
    }
    $rewrite = isset($args['rewrite']) && is_array($args['rewrite']) ? $args['rewrite'] : [];
    $rewrite['slug'] = SMA_EVENT_SINGLE_SLUG;
    // No /events/feed/ URLs: feeds would run event bodies through the_content, shortcodes and all.
    $rewrite['feeds'] = false;
    $args['rewrite'] = $rewrite;
    $args['has_archive'] = sma_pages_archive_on() ? SMA_EVENTS_ARCHIVE_SLUG : false;
    return $args;
}

/** ID of the site's own top-level page at /events/ (not trashed), or 0. */
function sma_pages_events_page_id()
{
    static $id = null;
    if ($id === null) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name = %s AND post_parent = 0 AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY ID LIMIT 1",
            SMA_EVENTS_ARCHIVE_SLUG
        ));
    }
    return $id;
}

/** Whether the plugin serves its own list at /events/: setting on and no site page there. */
function sma_pages_archive_on()
{
    return !empty(sma_setting('public_events_list')) && sma_pages_events_page_id() === 0;
}

/** What the stored rewrite rules must match: rule version plus the archive decision. */
function sma_pages_rewrite_key()
{
    return SMA_PAGES_REWRITE_VERSION . ':' . (sma_pages_archive_on() ? 'archive' : 'no-archive');
}

// Flush rewrite rules lazily when the rule version or archive decision changes, or the single-event rule is missing.
add_action('init', 'sma_pages_maybe_flush', 99);

function sma_pages_maybe_flush()
{
    $key   = sma_pages_rewrite_key();
    $stale = get_option('sma_pages_rewrite_version') !== $key;
    // Self-heal at most hourly, so a plugin that filters our rules out can't cause a flush per request.
    if (!$stale && !sma_pages_single_rule_present() && !get_transient('sma_pages_rewrite_healed')) {
        set_transient('sma_pages_rewrite_healed', 1, HOUR_IN_SECONDS);
        $stale = true;
    }
    if ($stale) {
        flush_rewrite_rules(false);
        update_option('sma_pages_rewrite_version', $key, true);
    }
}

/** True when the stored rules hold an /event/<slug>/ rule, or pretty permalinks are off. */
function sma_pages_single_rule_present()
{
    if ((string) get_option('permalink_structure') === '') {
        return true;
    }
    $rules = get_option('rewrite_rules');
    if (!is_array($rules)) {
        return false;
    }
    // A permalink front (e.g. /blog/%postname%/) prefixes the rule, so match after any "/".
    $prefix = '/' . SMA_EVENT_SINGLE_SLUG . '/';
    $query  = SMA_EVENTS_POST_TYPE . '=';
    foreach ($rules as $regex => $target) {
        if (strpos('/' . $regex, $prefix) !== false && strpos((string) $target, $query) !== false) {
            return true;
        }
    }
    return false;
}

// Old /events/<slug>/ links that 404 are redirected to /event/<slug>/ for published events.
add_action('template_redirect', 'sma_pages_redirect_old_single', 5);

function sma_pages_redirect_old_single()
{
    global $wp;
    if (!is_404() || !($wp instanceof WP) || !preg_match('#^' . SMA_EVENTS_ARCHIVE_SLUG . '/([^/]+)/?$#', (string) $wp->request, $m)) {
        return;
    }
    $event = get_page_by_path($m[1], OBJECT, SMA_EVENTS_POST_TYPE);
    if (!$event instanceof WP_Post || $event->post_status !== 'publish') {
        return;
    }
    wp_safe_redirect(get_permalink($event), 301);
    exit;
}

/** Which plugin view this request is, if any: 'list', 'single', 'passed' or ''. */
function sma_pages_view()
{
    if (is_post_type_archive(SMA_EVENTS_POST_TYPE)) {
        return 'list';
    }
    if (is_singular(SMA_EVENTS_POST_TYPE)) {
        $event = get_queried_object();
        // Passed in the event's own time zone.
        if ($event instanceof WP_Post && $event->post_status === 'publish' && sma_event_is_past($event)) {
            return 'passed';
        }
        return 'single';
    }
    return '';
}

add_filter('template_include', 'sma_pages_template', 99);

function sma_pages_template($template)
{
    $files = [
        'list'   => 'events-list.php',
        'single' => 'event-single.php',
        'passed' => 'event-passed.php',
    ];
    $view = sma_pages_view();
    if ($view === '' || is_404()) {
        return $template;
    }

    $ours = SMA_EVENTS_DIR . '/templates/' . $files[$view];
    return file_exists($ours) ? $ours : $template;
}

// The list template builds its own list, so the archive's main query is unpaged.
add_action('pre_get_posts', 'sma_pages_archive_query');

function sma_pages_archive_query($query)
{
    if (is_admin() || !($query instanceof WP_Query) || !$query->is_main_query() || !$query->is_post_type_archive(SMA_EVENTS_POST_TYPE)) {
        return;
    }
    $query->set('posts_per_page', -1);
    $query->set('no_found_rows', true);
}

add_action('wp', 'sma_pages_archive_404');

/** 404 for /events/page/N/, and for ?post_type=sma_event when the archive is off. */
function sma_pages_archive_404()
{
    global $wp_query;
    if (is_admin() || is_feed() || is_singular()) {
        return;
    }
    if (is_post_type_archive(SMA_EVENTS_POST_TYPE)) {
        if ((int) get_query_var('paged') <= 1) {
            return;
        }
    } elseif (get_query_var('post_type') !== SMA_EVENTS_POST_TYPE) {
        return;
    }
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}

/** Register the "sma-events" stylesheet on init, before the block that uses it. */
function sma_register_css()
{
    if (wp_style_is('sma-events', 'registered')) {
        return;
    }
    $file = SMA_EVENTS_DIR . '/assets/sma.css';
    wp_register_style(
        'sma-events',
        plugins_url('assets/sma.css', SMA_EVENTS_DIR . '/sma-events.php'),
        [],
        file_exists($file) ? (string) filemtime($file) : SMA_EVENTS_VERSION
    );
}

add_action('init', 'sma_register_css', 5);

/** Enqueue the plugin stylesheet (pages, shortcode, dashboard); after wp_head it prints in the footer. */
function sma_enqueue_css()
{
    sma_register_css();
    wp_enqueue_style('sma-events');
}

add_action('wp_enqueue_scripts', 'sma_pages_enqueue', 20);

function sma_pages_enqueue()
{
    if (is_404()) {
        return;
    }
    if (sma_pages_view() !== '' || sma_pages_content_lists_events()) {
        sma_enqueue_css();
    }
}

/** True when the current post's content holds [sma_events] or the sma/events block. */
function sma_pages_content_lists_events()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return false;
    }
    return has_shortcode($post->post_content, 'sma_events') || has_block('sma/events', $post);
}

// A passed event's page stays up for old links but isn't indexed.
add_filter('wp_robots', 'sma_pages_robots');

function sma_pages_robots($robots)
{
    if (sma_pages_view() === 'passed') {
        $robots['noindex'] = true;
    }
    return $robots;
}

/* ------------------------------------------------------------------ helpers */

/** Date and time labels and ISO strings for templates, in the venue's local time. */
function sma_event_when(WP_Post $event)
{
    $out = [
        'date_label'  => '',
        'date_iso'    => '',
        'start_label' => '',
        'start_iso'   => '',
        'end_label'   => '',
        'end_iso'     => '',
        'zone'        => '',
    ];

    $date = trim((string) get_post_meta($event->ID, 'event_date', true));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $out;
    }

    $tz = sma_event_timezone($event);
    try {
        $day = new DateTimeImmutable($date . ' 12:00', $tz);
    } catch (\Exception $e) {
        return $out;
    }
    $out['date_iso']   = $date;
    $out['date_label'] = date_i18n('l, F jS, Y', strtotime($date));

    foreach (['start', 'end'] as $which) {
        $hhmm = trim((string) get_post_meta($event->ID, $which . '_time', true));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $hhmm)) {
            continue;
        }
        try {
            $at = new DateTimeImmutable($date . ' ' . $hhmm, $tz);
        } catch (\Exception $e) {
            continue;
        }
        $out[$which . '_label'] = sma_events_time_label($hhmm);
        $out[$which . '_iso']   = $at->format('Y-m-d\TH:iP');
        if ($out['zone'] === '') {
            $out['zone'] = $at->format('T');
        }
    }
    if ($out['zone'] === '') {
        $out['zone'] = $day->format('T');
    }
    // Abbreviations like "+03" carry no meaning for a reader; drop them.
    if (!preg_match('/^[A-Z]{2,5}$/', $out['zone'])) {
        $out['zone'] = '';
    }

    return $out;
}

/** Google Maps search link for an address, or ''. */
function sma_event_map_url($address)
{
    $address = trim((string) $address);
    if ($address === '') {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
}

function sma_givebutter_url()
{
    return (string) apply_filters('sma_givebutter_url', SMA_GIVEBUTTER_URL);
}

function sma_costume_help_url()
{
    return (string) apply_filters('sma_costume_help_url', SMA_COSTUME_HELP_URL);
}

/** Public contact address shown when the form can't be embedded (filter "sma_contact_email"). */
function sma_contact_email()
{
    $email = sanitize_email((string) apply_filters('sma_contact_email', 'email@somanyangels.org'));
    return $email !== '' ? $email : 'email@somanyangels.org';
}

/** Where "see all events" links go: the site's /events/ page, else the plugin's archive, else ''. */
function sma_events_list_url()
{
    $page_id = sma_pages_events_page_id();
    if ($page_id > 0) {
        return get_post_status($page_id) === 'publish' ? (string) get_permalink($page_id) : '';
    }
    $url = get_post_type_archive_link(SMA_EVENTS_POST_TYPE);
    return $url ? (string) $url : '';
}

/** The volunteer page's URL if the site has one at /volunteer/, else ''. */
function sma_volunteer_page_url()
{
    $page = get_page_by_path('volunteer');
    $url  = ($page && $page->post_status === 'publish') ? get_permalink($page) : '';
    return (string) apply_filters('sma_volunteer_page_url', $url);
}

/** True for block themes, which have no header.php/footer.php. */
function sma_pages_block_theme()
{
    return function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_template_part');
}

/** Include a view with each $vars key as a local variable; views escape on output. */
function sma_include_view($file, array $vars = [])
{
    extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
    include $file;
}

/** Load a template part from templates/parts/ with variables in scope. */
function sma_pages_part($name, array $vars = [])
{
    $file = SMA_EVENTS_DIR . '/templates/parts/' . sanitize_file_name($name) . '.php';
    if (!file_exists($file)) {
        return;
    }
    sma_include_view($file, $vars);
}

/* ------------------------------------------------------- sign-up form embed */

/** The event slug to preselect in the sign-up form, set only while sma_signup_form_html() renders. */
function sma_pages_preselect($set = null)
{
    static $slug = '';
    if ($set !== null) {
        $slug = (string) $set;
    }
    return $slug;
}

// After the options are filled (priority 10); Fluent's Select uses attributes.value as the default (Select.php:64).
add_filter('fluentform/rendering_field_data_select', 'sma_pages_preselect_event', 20, 2);

function sma_pages_preselect_event($data, $form)
{
    $slug = sma_pages_preselect();
    if ($slug === '' || !is_array($data) || !sma_is_event_field($data, $form)) {
        return $data;
    }

    $known = false;
    foreach ($data['settings']['advanced_options'] ?? [] as $option) {
        if ((string) ($option['value'] ?? '') === $slug) {
            $known = true;
            break;
        }
    }
    if ($known) {
        $data['attributes']['value'] = $slug;
        if (isset($data['settings']['dynamic_default_value'])) {
            $data['settings']['dynamic_default_value'] = '';
        }
    }

    return $data;
}

/** The sign-up form's HTML with this event preselected, or '' when the form is unavailable. */
function sma_signup_form_html(WP_Post $event)
{
    if (!shortcode_exists('fluentform')) {
        return '';
    }
    $form_id = (int) sma_signup_form_id();
    if ($form_id <= 0) {
        return '';
    }

    sma_pages_preselect($event->post_name);
    try {
        $html = do_shortcode('[fluentform id="' . $form_id . '"]');
    } catch (\Throwable $e) {
        error_log('sma: sign-up form render failed (' . get_class($e) . ')');
        $html = '';
    }
    sma_pages_preselect('');

    return trim((string) $html);
}
