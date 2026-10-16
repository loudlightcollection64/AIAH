<?php
/** Plugin Name: SMA local dev only */
/** Description: Allows application passwords over plain http on the local Playground copy. */

if (!function_exists('sma_dev_is_local') || !sma_dev_is_local()) {
    return; // Only on the local copy; see 00-sma-dev-guard.php.
}

add_filter('wp_is_application_passwords_available', '__return_true');

// Serves Fluent Forms' default field definitions so the seed script builds forms as the editor would.
add_action('rest_api_init', function () {
    register_rest_route('sma-dev/v1', '/fluentform-defaults', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => function () {
            include WP_PLUGIN_DIR . '/fluentform/app/Services/FormBuilder/DefaultElements.php';
            $flat = [];
            foreach ($fluentformDefaultElements as $group) {
                foreach ($group as $key => $element) {
                    $flat[$key] = $element;
                }
            }
            return $flat;
        },
    ]);
});
