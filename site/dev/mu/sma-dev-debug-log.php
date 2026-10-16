<?php
/** Plugin Name: SMA local dev debug log */
/** Description: Logs PHP errors to wp-content/debug.log and serves its tail at /sma-dev/v1/debug-log (admin), since Playground's filesystem is not readable from the host. */

if (!function_exists('sma_dev_is_local') || !sma_dev_is_local()) {
    return; // Only on the local copy; see 00-sma-dev-guard.php.
}

// Set at runtime so neither a restart nor a wp-config change is needed.
@ini_set('log_errors', '1');
@ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
error_reporting(E_ALL);

add_action('rest_api_init', function () {
    $file = WP_CONTENT_DIR . '/debug.log';
    $admin = function () {
        return current_user_can('manage_options');
    };

    register_rest_route('sma-dev/v1', '/debug-log', [
        [
            'methods'             => 'GET',
            'permission_callback' => $admin,
            'args'                => ['lines' => ['type' => 'integer', 'default' => 200]],
            'callback'            => function ($request) use ($file) {
                if (!file_exists($file)) {
                    return ['exists' => false, 'lines' => []];
                }
                $all = file($file, FILE_IGNORE_NEW_LINES);
                $count = max(1, min(2000, (int) $request['lines']));
                return [
                    'exists'      => true,
                    'total_lines' => count($all),
                    'lines'       => array_slice($all, -$count),
                ];
            },
        ],
        [
            'methods'             => 'DELETE',
            'permission_callback' => $admin,
            'callback'            => function () use ($file) {
                if (file_exists($file)) {
                    file_put_contents($file, '');
                }
                return ['cleared' => true];
            },
        ],
    ]);
});
