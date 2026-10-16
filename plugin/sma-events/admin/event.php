<?php
// One event's families ($event or null for no event, $slug, $title, $details, $stage, $families, $counts, $unstaged).

if (!defined('ABSPATH')) {
    exit;
}

$total = array_sum($counts) + (int) $unstaged;
?>
<p><a href="<?php echo esc_url(sma_dash_url()); ?>">&larr; All events</a></p>

<div class="sma-admin__toolbar">
    <h2 class="sma-admin__heading"><?php echo esc_html($title); ?></h2>
    <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export&event=' . rawurlencode($slug)), 'sma_export')); ?>">Export families (CSV)</a>
    <?php if ($event) : ?>
        <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url(get_permalink($event)); ?>">Public page</a>
    <?php endif; ?>
    <?php if ($event && (sma_event_is_past($event) || !empty($counts['attended']))) : ?>
        <?php // Opens a preview of who gets one and both emails; only its confirm sends. ?>
        <a class="sma-btn sma-btn--primary" data-action="thank-all" href="<?php echo esc_url(sma_dash_preview_url('thank-all', 0, $slug)); ?>">Send thank-yous…</a>
    <?php endif; ?>
</div>

<?php $sma_setup_warning = $event ? sma_provision_warning($event) : ''; ?>
<?php if ($sma_setup_warning !== '') : ?>
<div class="notice notice-warning inline sma-admin__setup-warning"><p><?php echo esc_html($sma_setup_warning); ?> <a href="<?php echo esc_url(get_edit_post_link($event->ID, 'raw')); ?>">Open the event</a></p></div>
<?php endif; ?>
<?php foreach ($event ? sma_event_booking_warnings($event) : [] as $sma_alert) : ?>
<div class="notice notice-warning inline sma-admin__booking-warning" data-booking-warning="1" data-event="<?php echo esc_attr($event->post_name); ?>"><p><?php echo esc_html($sma_alert); ?></p></div>
<?php endforeach; ?>

<?php if ($event) : ?>
<p class="sma-muted">
    <?php echo esc_html(implode(' · ', array_filter([$details['date_label'], $details['time_label'], $details['location']]))); ?>
    <?php if ($details['booking_url'] === '') : ?>
        <br><strong>No booking link on this event yet.</strong> Approving will mark the family needs follow-up instead of emailing.
    <?php endif; ?>
</p>
<?php else : ?>
<p class="sma-muted sma-admin__no-event-note">
    No event selected: these families asked for an individual session or chose no date. Approval sends an event's booking link,
    so it isn't available here. Contact the family, then decline or set a stage by hand.
</p>
<?php endif; ?>

<nav class="sma-admin__filters" aria-label="Filter by stage">
    <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug])); ?>"<?php echo $stage === '' ? ' aria-current="true" class="is-current"' : ''; ?>><?php echo esc_html('All children: ' . $total); ?></a>
    <?php foreach ($counts as $s => $n) : ?>
        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug, 'stage' => $s])); ?>"<?php echo $stage === $s ? ' aria-current="true" class="is-current"' : ''; ?>><?php echo sma_dash_badge($s, $n); // escaped in sma_dash_badge ?></a>
    <?php endforeach; ?>
    <?php if ($unstaged) : ?>
        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug, 'stage' => 'none'])); ?>"<?php echo $stage === 'none' ? ' aria-current="true" class="is-current"' : ''; ?>><?php echo sma_dash_badge('none', $unstaged); // escaped in sma_dash_badge ?></a>
    <?php endif; ?>
    <?php sma_dash_include('waiting-nudge', ['waiting' => $waiting, 'slug' => $slug]); ?>
</nav>

<?php
// Row checkboxes join this form through form="sma-bulk-attended", since rows hold their own forms.
$sma_bulk = !empty($bulk_attended) && is_array($families) && array_filter($families, function ($f) {
    return in_array($f['stage'], SMA_DASH_BULK_ATTENDED_FROM, true);
});
if ($sma_bulk) : ?>
<form id="sma-bulk-attended" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sma-admin__form sma-admin__bulk" data-action="bulk-attended">
    <input type="hidden" name="action" value="sma_bulk_attended">
    <input type="hidden" name="event" value="<?php echo esc_attr($slug); ?>">
    <input type="hidden" name="view" value="event">
    <input type="hidden" name="stage_filter" value="<?php echo esc_attr($stage); ?>">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sma_bulk_attended_' . $slug)); ?>">
    <button type="submit" class="sma-btn sma-btn--primary">Mark ticked families attended</button>
    <span class="sma-muted">Tick "Attended" on each family who came. Nothing is emailed; send thank-yous afterwards with "Send thank-yous…".</span>
</form>
<?php endif; ?>

<?php if ($families === null) : ?>
    <p class="sma-muted">FluentCRM is not active.</p>
<?php elseif (!$families) : ?>
    <p class="sma-muted"><?php echo esc_html($stage !== '' ? 'No families in this stage.' : ($event ? 'No families for this event yet.' : 'No families without an event.')); ?></p>
<?php else :
    sma_dash_include('families-table', [
        'families'     => $families,
        'return_view'  => 'event',
        'stage_filter' => $stage,
        'bulk_form'    => $sma_bulk ? 'sma-bulk-attended' : '',
        // One row per child (sign-up entry); siblings are separate rows.
        'caption'      => ($stage !== '' ? sma_dash_stage_label($stage) . ' (children): ' : 'Children: ') . count($families),
    ]);
endif; ?>
