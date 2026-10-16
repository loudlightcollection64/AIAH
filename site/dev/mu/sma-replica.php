<?php
/** Plugin Name: SMA site replica (local only) */
/** Description: Gives the local copy the live site's header, footer, styles and page templates from site/replica (mounted at wp-content/sma-replica). */

if (!function_exists('sma_dev_is_local') || !sma_dev_is_local()) {
    return; // Only on the local copy; see 00-sma-dev-guard.php.
}

define('SMA_REPLICA_DIR', WP_CONTENT_DIR . '/sma-replica');
define('SMA_REPLICA_URL', content_url('sma-replica'));
define('SMA_REPLICA_KIT_CLASS', 'elementor-kit-4932'); // the live Elementor kit's body class, used by the copied CSS

/** True when site/replica is mounted and the active theme is Solace, whose header hooks the replica replaces. */
function sma_replica_active()
{
    static $active = null;
    if ($active === null) {
        $active = is_readable(SMA_REPLICA_DIR . '/header.html') && get_template() === 'solace';
    }
    return $active;
}

/** A copied markup file with its placeholders filled in on every request. */
function sma_replica_part($file)
{
    $path = SMA_REPLICA_DIR . '/' . $file;
    if (!is_readable($path)) {
        return '';
    }
    $html = (string) file_get_contents($path);
    return strtr($html, [
        '{{SITE_URL}}' => untrailingslashit(home_url()),
        '{{LOGO_URL}}' => SMA_REPLICA_URL . '/assets/logo-150.webp',
    ]);
}

/** Mark the menu item for the current page, as the live nav menu does. */
function sma_replica_mark_current($html)
{
    $home = untrailingslashit(home_url());
    if (is_front_page()) {
        $current = $home . '/';
    } elseif (is_singular()) {
        $current = (string) get_permalink();
    } else {
        return $html;
    }
    $needle = 'href="' . $current . '" class="elementor-item"';
    return str_replace($needle, 'href="' . $current . '" aria-current="page" class="elementor-item elementor-item-active"', $html);
}

/** Add an "Events" item after "Home" in the desktop and phone menus, marked current on event pages. */
function sma_replica_add_events_item($html)
{
    $events = untrailingslashit(home_url()) . '/events/';
    $html = preg_replace_callback(
        '#(<li class="menu-item[^"]*menu-item-home[^"]*"><a href="[^"]*" class="elementor-item"( tabindex="-1")?>Home</a></li>)#',
        function ($m) use ($events) {
            $tab = isset($m[2]) ? $m[2] : '';
            return $m[1] . "\n" . '<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-sma-events"><a href="'
                . esc_url($events) . '" class="elementor-item"' . $tab . '>Events</a></li>';
        },
        $html
    );
    if (is_singular('sma_event')) {
        $html = str_replace('href="' . $events . '" class="elementor-item"',
            'href="' . $events . '" aria-current="page" class="elementor-item elementor-item-active"', $html);
    }
    return $html;
}

// Mirrors the theme's Elementor header-footer compatibility: print the copy at priority 0, then drop the theme's header.
add_action('solace_do_header', 'sma_replica_header', 0);

function sma_replica_header()
{
    if (!sma_replica_active()) {
        return;
    }
    echo sma_replica_mark_current(sma_replica_add_events_item(sma_replica_part('header.html'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- copied site markup
    remove_all_actions('solace_do_top_bar');
    remove_all_actions('solace_do_header');
}

add_action('solace_do_footer', 'sma_replica_footer', 0);

function sma_replica_footer()
{
    if (!sma_replica_active()) {
        return;
    }
    echo sma_replica_part('footer.html'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- copied site markup
    remove_all_actions('solace_do_footer');
}

/* ------------------------------------------------------------ theme quirk */

// Solace echoes editor scripts before wp-admin sends headers ("headers already sent"); print them in the footer instead.
add_action('admin_init', 'sma_replica_move_theme_editor_scripts');

function sma_replica_move_theme_editor_scripts()
{
    if (!function_exists('solace_style_button_editor_gutenberg')) {
        return;
    }
    if (remove_action('enqueue_block_editor_assets', 'solace_style_button_editor_gutenberg')) {
        add_action('admin_print_footer_scripts', 'sma_replica_theme_editor_scripts_late');
    }
}

function sma_replica_theme_editor_scripts_late()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
        solace_style_button_editor_gutenberg();
    }
}

/* ----------------------------------------------------------------- styles */

// After the theme (priority 10), so the live customizer CSS overrides the local defaults.
add_action('wp_enqueue_scripts', 'sma_replica_enqueue', 20);

function sma_replica_enqueue()
{
    if (!sma_replica_active()) {
        return;
    }
    $ver = function ($rel) {
        $file = SMA_REPLICA_DIR . '/assets/' . $rel;
        return file_exists($file) ? (string) filemtime($file) : '1';
    };

    // The live site's Google fonts; offline, the system fallbacks apply.
    wp_enqueue_style('sma-replica-fonts', 'https://fonts.googleapis.com/css?family=Outfit%3A400%2C500%7CDM+Sans%3A400%2C500%2C600&display=swap', [], null);

    // Elementor's stylesheets only: the plugin is mounted but not activated, since the copied pages are static markup.
    $el_dir = WP_PLUGIN_DIR . '/elementor/assets/css/';
    $el_url = plugins_url('elementor/assets/css/');
    $el_ver = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '4.3.4';
    $deps   = ['sma-replica-fonts'];
    foreach (['frontend', 'widget-image', 'widget-spacer', 'widget-heading', 'widget-social-icons',
              'widget-icon-list', 'widget-counter', 'widget-video', 'widget-html', 'widget-text-editor'] as $name) {
        if (file_exists($el_dir . $name . '.min.css')) {
            wp_enqueue_style('sma-replica-el-' . $name, $el_url . $name . '.min.css', $deps, $el_ver);
            $deps = ['sma-replica-el-' . $name];
        }
    }
    if (file_exists($el_dir . 'conditionals/apple-webkit.min.css')) {
        wp_enqueue_style('sma-replica-el-apple', $el_url . 'conditionals/apple-webkit.min.css', $deps, $el_ver);
    }

    // Live Elementor CSS, then the live customizer CSS, then the replica's own rules.
    wp_enqueue_style('sma-replica-elementor-live', SMA_REPLICA_URL . '/assets/elementor-live.css', $deps, $ver('elementor-live.css'));
    wp_enqueue_style('sma-replica-solace-live', SMA_REPLICA_URL . '/assets/solace-live.css', ['sma-replica-elementor-live'], $ver('solace-live.css'));
    wp_enqueue_style('sma-replica', SMA_REPLICA_URL . '/assets/replica.css', ['sma-replica-solace-live'], $ver('replica.css'));
    wp_enqueue_script('sma-replica', SMA_REPLICA_URL . '/assets/replica.js', [], $ver('replica.js'), true);
}

// Add the live kit's body class, which scopes the copied CSS, and the classes Elementor adds to its pages.
add_filter('body_class', 'sma_replica_body_class');

function sma_replica_body_class($classes)
{
    if (!sma_replica_active()) {
        return $classes;
    }
    $classes[] = 'elementor-default';
    $classes[] = SMA_REPLICA_KIT_CLASS;
    $classes[] = 'ehf-template-solace';
    $classes[] = 'sma-replica';
    $kind = sma_replica_template_kind();
    if ($kind !== '') {
        $classes[] = 'elementor-page';
        if ($kind === 'full') {
            $classes[] = 'page-template-elementor_header_footer';
            $classes[] = 'elementor-template-full-width';
        }
    }
    return $classes;
}

/* -------------------------------------------------------------- templates */

// Per-page replica template, set over REST: "full" (no theme wrapper) or "default" (theme wrapper); neither shows a title.
add_action('init', 'sma_replica_register_meta');

function sma_replica_register_meta()
{
    register_post_meta('page', 'sma_replica_template', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => function ($value) {
            return in_array($value, ['full', 'default'], true) ? $value : '';
        },
        'auth_callback'     => function () {
            return current_user_can('edit_pages');
        },
    ]);
}

function sma_replica_template_kind()
{
    if (!is_page()) {
        return '';
    }
    $kind = (string) get_post_meta(get_queried_object_id(), 'sma_replica_template', true);
    return in_array($kind, ['full', 'default'], true) ? $kind : '';
}

// Hide Solace's fresh-install welcome banner, already dismissed on the live site.
add_action('after_setup_theme', function () {
    remove_action('admin_notices', 'solace_display_admin_notice');
}, 99);

add_filter('template_include', 'sma_replica_template', 50);

function sma_replica_template($template)
{
    if (!sma_replica_active()) {
        return $template;
    }
    $kind = sma_replica_template_kind();
    if ($kind === '') {
        return $template;
    }
    $file = SMA_REPLICA_DIR . '/templates/page-' . $kind . '.php';
    return file_exists($file) ? $file : $template;
}
