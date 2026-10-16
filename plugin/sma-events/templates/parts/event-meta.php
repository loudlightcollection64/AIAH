<?php
// Event facts as a definition list ($event; $compact shows date, time and venue only).

if (!defined('ABSPATH')) {
    exit;
}

$sma_event   = $event;
$sma_compact = !empty($compact);
$sma_when    = sma_event_when($sma_event);
$sma_venue   = trim((string) get_post_meta($sma_event->ID, 'venue', true));
$sma_address = trim((string) get_post_meta($sma_event->ID, 'address', true));
$sma_canvas  = trim((string) get_post_meta($sma_event->ID, 'canvas_handover', true));
$sma_map     = sma_event_map_url($sma_address);
?>
<dl class="sma-event-meta<?php echo $sma_compact ? ' sma-event-meta--compact' : ''; ?>">
<?php if ($sma_when['date_label'] !== '') : ?>
    <dt>Date</dt>
    <dd><time datetime="<?php echo esc_attr($sma_when['date_iso']); ?>"><?php echo esc_html($sma_when['date_label']); ?></time></dd>
<?php endif; ?>
<?php if ($sma_when['start_label'] !== '') : ?>
    <dt>Time</dt>
    <dd>
        <time datetime="<?php echo esc_attr($sma_when['start_iso']); ?>"><?php echo esc_html($sma_when['start_label']); ?></time><?php if ($sma_when['end_label'] !== '') : ?>
        to <time datetime="<?php echo esc_attr($sma_when['end_iso']); ?>"><?php echo esc_html($sma_when['end_label']); ?></time><?php endif; ?>
        <?php if ($sma_when['zone'] !== '') : ?><span class="sma-muted"><?php echo esc_html($sma_when['zone']); ?></span><?php endif; ?>
    </dd>
<?php endif; ?>
<?php if ($sma_venue !== '') : ?>
    <dt>Venue</dt>
    <dd><?php echo esc_html($sma_venue); ?></dd>
<?php endif; ?>
<?php if (!$sma_compact && $sma_address !== '') : ?>
    <dt>Address</dt>
    <dd>
        <span class="sma-event-address"><?php echo esc_html($sma_address); ?></span>
        <a class="sma-map-link" href="<?php echo esc_url($sma_map); ?>">Open in Google Maps</a>
    </dd>
<?php endif; ?>
<?php if (!$sma_compact && $sma_canvas !== '') : ?>
    <dt>Your canvas</dt>
    <dd><?php echo esc_html($sma_canvas); ?></dd>
<?php endif; ?>
</dl>
