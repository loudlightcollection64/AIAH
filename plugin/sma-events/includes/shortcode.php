<?php
// The events list for any page: [sma_events limit="3" show_past="1" heading="..."] and the sma/events block.

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('sma_events', 'sma_events_shortcode');

function sma_events_shortcode($atts)
{
    $atts = shortcode_atts([
        'limit'     => 0,
        'show_past' => 0,
        'heading'   => '',
    ], is_array($atts) ? $atts : [], 'sma_events');

    $show_past = in_array(strtolower(trim((string) $atts['show_past'])), ['1', 'true', 'yes', 'on'], true);
    return sma_events_list_html((int) $atts['limit'], $show_past, (string) $atts['heading']);
}

/** Published events: upcoming soonest first, then past ones newest first if asked; $limit <= 0 means all. */
function sma_events_for_list($limit, $show_past)
{
    $events = sma_events_open();

    if ($show_past) {
        $older = get_posts([
            'post_type'      => SMA_EVENTS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => 'event_date',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
        ]);
        foreach ($older as $event) {
            if (sma_event_is_past($event)) {
                $events[] = $event;
            }
        }
    }

    return $limit > 0 ? array_slice($events, 0, $limit) : $events;
}

/** The list's HTML, wrapped in .sma so the plugin styles apply inside any theme or builder. */
function sma_events_list_html($limit, $show_past, $heading)
{
    static $instance = 0;
    $instance++;

    sma_enqueue_css();

    $heading = trim(wp_strip_all_tags($heading));
    $prefix  = 'sma-events-' . $instance . '-';

    ob_start();
    echo '<div class="sma sma-events-embed">';
    if ($heading !== '') {
        echo '<h2 class="sma-events-embed__heading">' . esc_html($heading) . '</h2>';
    }
    sma_pages_part('event-list', [
        'events'    => sma_events_for_list($limit, $show_past),
        'level'     => $heading !== '' ? 3 : 2,
        'id_prefix' => $prefix,
    ]);
    echo '</div>';
    return (string) ob_get_clean();
}

/* ------------------------------------------------------------------ block */

// After the stylesheet is registered (init priority 5).
add_action('init', 'sma_events_register_block', 20);

function sma_events_register_block()
{
    if (!function_exists('register_block_type')) {
        return;
    }

    // No build step: the editor script is inline ES5 on a source-less handle.
    wp_register_script(
        'sma-events-block',
        false,
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
        SMA_EVENTS_VERSION,
        true
    );
    wp_add_inline_script('sma-events-block', sma_events_block_js());

    register_block_type('sma/events', [
        'api_version'           => 3,
        'title'                 => 'SMA upcoming events',
        'category'              => 'widgets',
        'icon'                  => 'calendar-alt',
        'editor_script_handles' => ['sma-events-block'],
        'style_handles'         => ['sma-events'],
        'render_callback'       => 'sma_events_block_render',
        'attributes'            => [
            'limit'    => ['type' => 'integer', 'default' => 0],
            'showPast' => ['type' => 'boolean', 'default' => false],
            'heading'  => ['type' => 'string', 'default' => ''],
        ],
    ]);
}

function sma_events_block_render($attributes)
{
    $attributes = is_array($attributes) ? $attributes : [];
    return sma_events_list_html(
        isset($attributes['limit']) ? (int) $attributes['limit'] : 0,
        !empty($attributes['showPast']),
        isset($attributes['heading']) ? (string) $attributes['heading'] : ''
    );
}

/** Editor side of sma/events: sidebar settings and a server-rendered preview. */
function sma_events_block_js()
{
    return <<<'JS'
(function (wp) {
  if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components || !wp.serverSideRender) { return; }
  var el = wp.element.createElement;
  var be = wp.blockEditor;
  var c = wp.components;
  wp.blocks.registerBlockType('sma/events', {
    apiVersion: 3,
    title: 'SMA upcoming events',
    category: 'widgets',
    icon: 'calendar-alt',
    attributes: {
      limit: { type: 'integer', default: 0 },
      showPast: { type: 'boolean', default: false },
      heading: { type: 'string', default: '' }
    },
    edit: function (props) {
      var a = props.attributes;
      return el('div', be.useBlockProps(),
        el(be.InspectorControls, null,
          el(c.PanelBody, { title: 'Event list' },
            el(c.TextControl, { label: 'Heading (optional)', value: a.heading, onChange: function (v) { props.setAttributes({ heading: v }); } }),
            el(c.TextControl, { label: 'How many to show (0 = all)', type: 'number', min: 0, value: String(a.limit), onChange: function (v) { props.setAttributes({ limit: Math.max(0, parseInt(v, 10) || 0) }); } }),
            el(c.ToggleControl, { label: 'Also show past events', checked: !!a.showPast, onChange: function (v) { props.setAttributes({ showPast: !!v }); } })
          )
        ),
        el(wp.serverSideRender, { block: 'sma/events', attributes: a })
      );
    },
    save: function () { return null; }
  });
})(window.wp);
JS;
}
