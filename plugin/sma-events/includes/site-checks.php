<?php
/** Read-only site checks for the Settings screen: each is [id, status pass|warn|info, title, how]. */

if (!defined('ABSPATH')) {
    exit;
}

/** Every check, in display order. */
function sma_site_checks()
{
    $checks = [];
    foreach ([
        'sma_site_check_timezone',
        'sma_site_check_debug_display',
        'sma_site_check_php',
        'sma_site_check_smtp',
        'sma_site_check_signup_notifications',
        'sma_site_check_events_menu',
        'sma_site_check_admins',
        'sma_site_check_two_factor',
    ] as $fn) {
        try {
            $checks[] = $fn();
        } catch (\Throwable $e) {
            error_log('sma: site check ' . $fn . ' failed (' . get_class($e) . ')');
        }
    }
    return $checks;
}

function sma_site_check($id, $status, $title, $how = '')
{
    return ['id' => $id, 'status' => $status, 'title' => $title, 'how' => $how];
}

/** Active plugin files ("dir/file.php"), this site's and network-wide. */
function sma_site_active_plugins()
{
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }
    return array_values(array_unique(array_map('strval', $active)));
}

/** The first active plugin whose folder is one of $dirs, or ''. */
function sma_site_active_plugin_in(array $dirs)
{
    foreach (sma_site_active_plugins() as $file) {
        $dir = strtok($file, '/');
        if (in_array($dir, $dirs, true)) {
            return $file;
        }
    }
    return '';
}

/* --------------------------------------------------------------------------------- checks */

/** A fixed UTC offset ignores daylight saving, so scheduled times shift by an hour twice a year. */
function sma_site_check_timezone()
{
    $city = (string) get_option('timezone_string');
    if ($city !== '') {
        return sma_site_check('timezone', 'pass', 'Site time zone: ' . $city . '.');
    }
    $offset = (float) get_option('gmt_offset');
    $label  = 'UTC' . ($offset >= 0 ? '+' : '') . rtrim(rtrim(number_format($offset, 2, '.', ''), '0'), '.');
    return sma_site_check(
        'timezone',
        'warn',
        'Site time zone is a fixed offset (' . $label . '), not a city.',
        'Booking times and reminders shift by an hour when the clocks change. In Settings → General → Timezone, choose "New York".'
    );
}

/** PHP errors printed on visitors' pages show file paths and settings. */
function sma_site_check_debug_display()
{
    $wp_display = defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
    $ini        = strtolower(trim((string) ini_get('display_errors')));
    $ini_on     = !in_array($ini, ['', '0', 'off', 'false', 'no', 'stderr'], true);
    if (!$wp_display && !$ini_on) {
        return sma_site_check('debug_display', 'pass', 'Error messages are hidden from visitors.');
    }
    return sma_site_check(
        'debug_display',
        'warn',
        'Error messages are shown to visitors' . ($wp_display ? ' (WP_DEBUG_DISPLAY is on).' : ' (PHP display_errors is on).'),
        'Any PHP error prints file paths on the page. Set WP_DEBUG_DISPLAY to false in wp-config.php (in IONOS, or ask their support); errors still go to the log.'
    );
}

function sma_site_check_php()
{
    if (version_compare(PHP_VERSION, '8.1', '>=')) {
        return sma_site_check('php', 'pass', 'PHP ' . PHP_VERSION . '.');
    }
    return sma_site_check(
        'php',
        'warn',
        'PHP ' . PHP_VERSION . ' no longer gets security fixes.',
        'Back up the site, then choose a newer PHP version (8.2 or later) in the IONOS panel and check the sign-up form and the dashboard still work.'
    );
}

/** FluentSMTP active with at least one connection (option "fluentmail-settings", key "connections"). */
function sma_site_check_smtp()
{
    $active = defined('FLUENTMAIL') || sma_site_active_plugin_in(['fluent-smtp']) !== '';
    if (!$active) {
        return sma_site_check(
            'smtp',
            'warn',
            'FluentSMTP is not active.',
            'Without it, the site\'s emails to families may not arrive or may land in spam. Activate FluentSMTP and connect it to your Google Workspace mailbox.'
        );
    }
    $settings    = get_option('fluentmail-settings', []);
    $connections = is_array($settings) && !empty($settings['connections']) && is_array($settings['connections']) ? $settings['connections'] : [];
    if (!$connections) {
        return sma_site_check(
            'smtp',
            'warn',
            'FluentSMTP is active but has no email connection.',
            'Emails may not arrive or may land in spam. In Settings → FluentSMTP, add a connection to your Google Workspace mailbox and send its test email.'
        );
    }
    return sma_site_check('smtp', 'pass', 'FluentSMTP is active with ' . count($connections) . (count($connections) === 1 ? ' connection.' : ' connections.'));
}

/** Enabled sign-up notifications and feeds (fluentform_form_meta) must not use {all_data} or a diagnosis {inputs.*}. */
function sma_site_check_signup_notifications()
{
    if (!defined('FLUENTFORM')) {
        return sma_site_check('signup_notifications', 'info', 'Fluent Forms is not active, so the sign-up form\'s emails were not checked.');
    }
    $form_id = (int) sma_signup_form_id();
    if ($form_id <= 0) {
        return sma_site_check('signup_notifications', 'info', 'No sign-up form found, so its emails were not checked.', 'Pick the sign-up form above and save.');
    }

    $fields = sma_diagnosis_field_names();
    $forbidden = sma_forbidden_field_names($form_id);
    if (is_array($forbidden)) {
        $fields = array_merge($fields, $forbidden);
    }
    $fields = array_values(array_unique(array_filter(array_map('strval', $fields))));

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, value FROM {$wpdb->prefix}fluentform_form_meta
          WHERE form_id = %d AND (meta_key = %s OR meta_key LIKE %s)
          ORDER BY id ASC",
        $form_id,
        'notifications',
        '%' . $wpdb->esc_like('_feeds')
    ));

    $found = [];
    foreach ((array) $rows as $row) {
        $raw   = (string) $row->value;
        $value = json_decode($raw, true);
        if (!is_array($value)) {
            continue;
        }
        if (array_key_exists('enabled', $value) && in_array($value['enabled'], [false, 0, '0', 'false', 'no', ''], true)) {
            continue;
        }
        $hit = stripos($raw, '{all_data') !== false;
        foreach ($fields as $name) {
            if ($hit) {
                break;
            }
            $hit = stripos($raw, '{inputs.' . $name . '}') !== false || stripos($raw, '{inputs.' . $name . '.') !== false;
        }
        if ($hit) {
            $kind  = $row->meta_key === 'notifications' ? 'email' : str_replace('_feeds', '', (string) $row->meta_key) . ' feed';
            $name  = isset($value['name']) && is_scalar($value['name']) ? trim((string) $value['name']) : '';
            $found[] = ($name !== '' ? '"' . $name . '"' : 'unnamed') . ' (' . $kind . ')';
        }
    }

    if (!$found) {
        return sma_site_check('signup_notifications', 'pass', 'The sign-up form\'s notifications and feeds don\'t include the diagnosis.');
    }
    return sma_site_check(
        'signup_notifications',
        'warn',
        'Your admin email includes the diagnosis: ' . implode(', ', $found) . ' on the sign-up form.',
        'In Fluent Forms, open the sign-up form → Settings → Email Notifications (and Integrations), and replace {all_data} or the diagnosis field with only the answers you need.'
    );
}

/** An "Events" link in any WordPress menu; info only, since a page-builder header is not a WordPress menu. */
function sma_site_check_events_menu()
{
    $events_path = untrailingslashit((string) wp_parse_url(home_url('/events/'), PHP_URL_PATH));
    $page        = get_page_by_path('events');
    foreach ((array) wp_get_nav_menus() as $menu) {
        foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
            $path = untrailingslashit((string) wp_parse_url((string) $item->url, PHP_URL_PATH));
            if ($path === $events_path || ($page && $item->object === 'page' && (int) $item->object_id === (int) $page->ID)) {
                return sma_site_check('events_menu', 'pass', 'Your "' . $menu->name . '" menu links to the events page.');
            }
        }
    }
    return sma_site_check(
        'events_menu',
        'info',
        'No menu links to the events page.',
        'Families only find events through a home-page button. Add "Events" (pointing at /events/) to your site menu in Appearance → Menus, or in your Elementor header.'
    );
}

/** How many accounts can see every family. */
function sma_site_check_admins()
{
    $admins = count(get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 100]));
    return sma_site_check(
        'admins',
        'info',
        ($admins === 1 ? '1 administrator account' : $admins . ' administrator accounts') . ' can see every family.',
        'Remove (or lower to Editor) any administrator account no one uses, in Users.'
    );
}

/** Whether a known two-factor login plugin is active (per-user enrolment can't be read reliably). */
function sma_site_check_two_factor()
{
    $plugin = sma_site_active_plugin_in([
        'two-factor',
        'wp-2fa',
        'wp-2fa-premium',
        'wordfence',
        'wordfence-login-security',
        'two-factor-authentication',
        'miniorange-2-factor-authentication',
        'google-authenticator',
    ]);
    if ($plugin !== '') {
        return sma_site_check(
            'two_factor',
            'pass',
            'A two-factor login plugin is active (' . strtok($plugin, '/') . ').',
            'Make sure each administrator has turned two-factor login on for their own account.'
        );
    }
    return sma_site_check(
        'two_factor',
        'warn',
        'No two-factor login plugin is active.',
        'A leaked administrator password would expose every family. Activate Wordfence (already installed) and turn on its two-factor login for each administrator.'
    );
}
