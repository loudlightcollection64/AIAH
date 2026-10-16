<?php
/** Plugin Name: SMA local dev mail log */
/** Description: Captures outgoing email in a log shown under Tools → Mail log (dev) and at /sma-dev/v1/mail-log instead of sending it. */

if (!function_exists('sma_dev_is_local') || !sma_dev_is_local()) {
    return; // Only on the local copy; see 00-sma-dev-guard.php.
}

const SMA_DEV_MAIL_LOG_OPTION = 'sma_dev_mail_log';
const SMA_DEV_MAIL_LOG_MAX = 200;

// Short-circuit wp_mail (WordPress 5.7+): log the message and report success.
add_filter('pre_wp_mail', function ($return, $atts) {
    $log = get_option(SMA_DEV_MAIL_LOG_OPTION, []);
    $log[] = [
        'time'    => current_time('mysql'),
        'to'      => $atts['to'],
        'subject' => $atts['subject'],
        'message' => $atts['message'],
        'headers' => $atts['headers'],
    ];
    update_option(SMA_DEV_MAIL_LOG_OPTION, array_slice($log, -SMA_DEV_MAIL_LOG_MAX), false);
    return true;
}, 10, 2);

add_action('rest_api_init', function () {
    register_rest_route('sma-dev/v1', '/mail-log', [
        [
            'methods'             => 'GET',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'callback'            => function () {
                return array_reverse(get_option(SMA_DEV_MAIL_LOG_OPTION, []));
            },
        ],
        [
            'methods'             => 'DELETE',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'callback'            => function () {
                delete_option(SMA_DEV_MAIL_LOG_OPTION);
                return ['cleared' => true];
            },
        ],
    ]);
});

add_action('admin_menu', function () {
    add_management_page('Mail log (dev)', 'Mail log (dev)', 'manage_options', 'sma-dev-mail-log', function () {
        $log = array_reverse(get_option(SMA_DEV_MAIL_LOG_OPTION, []));
        echo '<div class="wrap"><h1>Mail log (dev)</h1><p>Emails the site would have sent. Newest first.</p>';
        if (!$log) {
            echo '<p><em>No email yet.</em></p>';
        }
        foreach ($log as $mail) {
            $to = is_array($mail['to']) ? implode(', ', $mail['to']) : $mail['to'];
            printf(
                '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin:12px 0"><p><strong>%s</strong><br>To: %s<br>%s</p><div style="border-top:1px solid #eee;padding-top:8px">%s</div></div>',
                esc_html($mail['subject']),
                esc_html($to),
                esc_html($mail['time']),
                wp_kses_post(wpautop($mail['message']))
            );
        }
        echo '</div>';
    });
});
