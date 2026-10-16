<?php
// Read-only "Set-up status" box ($post, $status, $session, $days, $tag_id, $warning, $published).

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if (!$published) : ?>
    <p>Not published yet. Publishing makes the booking session, the reminder emails and the CRM tag.</p>
<?php endif; ?>

<?php if ($warning !== '') : ?>
    <p style="color:#b32d2e"><strong><?php echo esc_html($warning); ?></strong></p>
    <p class="description">Save the event again to retry.</p>
<?php elseif ($published && $status['ok']) : ?>
    <p><strong>Booking is set up.</strong></p>
<?php endif; ?>

<p>
    <strong>Booking link</strong><br>
    <?php if ($status['booking_url'] !== '') : ?>
        <a href="<?php echo esc_url($status['booking_url']); ?>" target="_blank" rel="noopener" style="word-break:break-all"><?php echo esc_html($status['booking_url']); ?></a><br>
        <span class="description">Sent to families when you approve them. Don't share it publicly.</span>
    <?php else : ?>
        <span class="description">None yet.</span>
    <?php endif; ?>
</p>

<p>
    <strong>Booking session</strong><br>
    <?php echo esc_html($session !== '' ? $session : 'None yet.'); ?>
</p>

<p>
    <strong>Reminder emails</strong><br>
    <?php echo esc_html($days ? implode(', ', $days) . ' days before each family\'s session' : 'Off (no reminder days in Settings)'); ?>
</p>

<p>
    <strong>CRM tag</strong><br>
    <?php echo esc_html($tag_id ? 'Tag ' . $tag_id : 'None yet.'); ?>
</p>

<?php if ($status['provisioned_at'] !== '') : ?>
    <p class="description"><?php echo esc_html('Last set up: ' . $status['provisioned_at']); ?></p>
<?php endif; ?>
