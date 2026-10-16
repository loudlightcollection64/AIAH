<?php
// A past event's page: old links still work (noindex) and point to upcoming events.

if (!defined('ABSPATH')) {
    exit;
}

$sma_event = get_queried_object();
if (!$sma_event instanceof WP_Post) {
    return;
}

$sma_city = trim((string) get_post_meta($sma_event->ID, 'city', true));
$sma_when = sma_event_when($sma_event);
$sma_list_url = sma_events_list_url();

sma_pages_part('open');
?>
<article class="sma-event sma-event--passed" aria-labelledby="sma-event-title">
    <header class="sma-event__hero">
        <?php if ($sma_city !== '') : ?>
            <span class="sma-eyebrow"><?php echo esc_html($sma_city); ?></span>
        <?php endif; ?>
        <h1 id="sma-event-title">This event has passed</h1>
        <p class="sma-event__lead">
            <?php if ($sma_when['date_label'] !== '') : ?>
                This photo day took place on <time datetime="<?php echo esc_attr($sma_when['date_iso']); ?>"><?php echo esc_html($sma_when['date_label']); ?></time>.
            <?php else : ?>
                This photo day has already taken place.
            <?php endif; ?>
            <?php if ($sma_list_url !== '') : ?>Take a look at what&rsquo;s coming up next.<?php endif; ?>
        </p>
        <?php if ($sma_list_url !== '') : ?>
            <p><a class="sma-btn sma-btn--primary" href="<?php echo esc_url($sma_list_url); ?>">See upcoming events</a></p>
        <?php endif; ?>
    </header>
</article>
<?php
sma_pages_part('close');
