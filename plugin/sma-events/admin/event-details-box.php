<?php
// "Event details" box on the event edit screen ($post, $values, $site_zone); one full-width input per row for phones.

if (!defined('ABSPATH')) {
    exit;
}

$sma_zones = timezone_identifiers_list();
$sma_field = function ($key, $label, $type, $help = '', $extra = '') use ($values) {
    $id = 'sma-event-' . str_replace('_', '-', $key);
    ?>
    <p class="sma-event-field">
        <label for="<?php echo esc_attr($id); ?>"><strong><?php echo esc_html($label); ?></strong></label><br>
        <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="sma_event[<?php echo esc_attr($key); ?>]"
            value="<?php echo esc_attr($values[$key]); ?>" class="widefat" style="width:100%;max-width:100%;box-sizing:border-box"<?php echo $extra; // fixed attribute strings below ?>>
        <?php if ($help !== '') : ?>
            <span class="description"><?php echo esc_html($help); ?></span>
        <?php endif; ?>
    </p>
    <?php
};

wp_nonce_field('sma_event_details_' . $post->ID, 'sma_event_details_nonce');
?>
<p class="description">Publishing the event opens booking: the site makes the booking session, the reminder emails and the CRM tag by itself.</p>
<?php
$sma_field('city', 'City', 'text', 'Shown on the sign-up form, e.g. "Pittsburgh".');
$sma_field('venue', 'Venue', 'text');
$sma_field('address', 'Address', 'text');
$sma_field('event_date', 'Date', 'date');
$sma_field('start_time', 'Start time', 'time', 'First session starts at this time.', ' step="300"');
$sma_field('end_time', 'End time', 'time', 'Last session ends by this time.', ' step="300"');
?>
<p class="sma-event-field">
    <label for="sma-event-timezone"><strong>Time zone</strong></label><br>
    <select id="sma-event-timezone" name="sma_event[timezone]" class="widefat" style="width:100%;max-width:100%;box-sizing:border-box">
        <?php if (!in_array($values['timezone'], $sma_zones, true)) : ?>
            <option value=""<?php selected($values['timezone'], ''); ?>><?php echo esc_html('Site time zone (' . $site_zone . ')'); ?></option>
        <?php endif; ?>
        <?php foreach ($sma_zones as $sma_zone) : ?>
            <option value="<?php echo esc_attr($sma_zone); ?>"<?php selected($values['timezone'], $sma_zone); ?>><?php echo esc_html(str_replace('_', ' ', $sma_zone)); ?></option>
        <?php endforeach; ?>
    </select>
    <span class="description">Where the venue is. Times above are in this zone.</span>
</p>
<?php
$sma_field('capacity', 'Capacity', 'number', 'Most families that can book on the day (1 to 500). Leave empty for no limit.', ' min="1" max="500" step="1" inputmode="numeric"');
$sma_field('canvas_handover', 'Canvas handover', 'text', 'How families receive their canvas.');
unset($sma_zones, $sma_zone, $sma_field);
