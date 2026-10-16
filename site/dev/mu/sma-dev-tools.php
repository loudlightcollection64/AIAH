<?php
/** Plugin Name: SMA local dev tools */
/** Description: Admin-only test routes for the local copy: list scheduled actions, backdate a stage, issue test-user passwords. */

if (!function_exists('sma_dev_is_local') || !sma_dev_is_local()) {
    return; // Only on the local copy; see 00-sma-dev-guard.php.
}

add_action('rest_api_init', function () {
    $admin = function () {
        return current_user_can('manage_options');
    };

    register_rest_route('sma-dev/v1', '/scheduled-actions', [
        'methods'             => 'GET',
        'permission_callback' => $admin,
        'args'                => [
            'hook'   => ['type' => 'string', 'required' => true],
            'status' => ['type' => 'string', 'default' => 'pending'],
        ],
        'callback'            => function ($request) {
            if (!function_exists('as_get_scheduled_actions')) {
                return new WP_Error('sma_dev_no_action_scheduler', 'Action Scheduler is not loaded.', ['status' => 503]);
            }
            $actions = as_get_scheduled_actions([
                'hook'     => (string) $request['hook'],
                'status'   => (string) $request['status'],
                'per_page' => 500,
            ]);
            $out = [];
            foreach ((array) $actions as $id => $action) {
                $schedule = method_exists($action, 'get_schedule') ? $action->get_schedule() : null;
                $date     = ($schedule && method_exists($schedule, 'get_date')) ? $schedule->get_date() : null;
                $out[] = [
                    'id'            => (int) $id,
                    'args'          => method_exists($action, 'get_args') ? $action->get_args() : [],
                    'scheduled_gmt' => $date ? $date->format('c') : null,
                    'status'        => (string) $request['status'],
                ];
            }
            return $out;
        },
    ]);

    register_rest_route('sma-dev/v1', '/backdate-stage', [
        'methods'             => 'POST',
        'permission_callback' => $admin,
        'args'                => [
            'contact_id' => ['type' => 'integer', 'required' => true],
            'event'      => ['type' => 'string', 'required' => true],
            'days'       => ['type' => 'integer', 'required' => true],
        ],
        'callback'            => function ($request) {
            if (!function_exists('sma_stage_map') || !function_exists('sma_contact_meta_set')) {
                return new WP_Error('sma_dev_no_plugin', 'The sma-events plugin is not active.', ['status' => 503]);
            }
            $cid = (int) $request['contact_id'];
            $key = sma_stage_key((string) $request['event']);
            $map = sma_stage_map($cid);
            if (!isset($map[$key]['stage'])) {
                return new WP_Error('sma_dev_no_stage', 'That contact has no stage for this event.', ['status' => 404]);
            }
            $map[$key]['changed_at'] = wp_date('Y-m-d H:i:s', time() - max(0, (int) $request['days']) * DAY_IN_SECONDS);
            sma_contact_meta_set($cid, SMA_STAGES_META, $map);
            return ['contact_id' => $cid, 'event' => $key, 'stage' => $map[$key]['stage'], 'changed_at' => $map[$key]['changed_at']];
        },
    ]);

    register_rest_route('sma-dev/v1', '/test-user', [
        'methods'             => 'POST',
        'permission_callback' => $admin,
        'args'                => [
            'login' => ['type' => 'string', 'required' => true],
            'role'  => ['type' => 'string', 'required' => true],
        ],
        'callback'            => function ($request) {
            $login = sanitize_user((string) $request['login'], true);
            $role  = (string) $request['role'];
            if ($login === '' || !get_role($role)) {
                return new WP_Error('sma_dev_bad_user', 'Unknown role or empty login.', ['status' => 400]);
            }
            $user = get_user_by('login', $login);
            if (!$user) {
                $id = wp_insert_user([
                    'user_login' => $login,
                    'user_email' => $login . '@example.com',
                    'user_pass'  => wp_generate_password(24),
                    'role'       => $role,
                ]);
                if (is_wp_error($id)) {
                    return $id;
                }
                $user = get_user_by('id', $id);
            } else {
                $user->set_role($role);
            }
            $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => 'local test ' . time()]);
            if (is_wp_error($created)) {
                return $created;
            }
            return [
                'user_id'      => (int) $user->ID,
                'login'        => $login,
                'role'         => $role,
                'app_password' => $created[0],
            ];
        },
    ]);
});
