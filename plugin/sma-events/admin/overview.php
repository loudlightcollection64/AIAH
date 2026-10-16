<?php
// Events overview: $rows (event, details, capacity, past, family and volunteer counts) and $no_event.

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sma-admin__toolbar">
    <h2 class="sma-admin__heading">Events</h2>
    <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export&event=all'), 'sma_export')); ?>">Export all families (CSV)</a>
    <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export_volunteers&event=all'), 'sma_export')); ?>">Export all volunteers (CSV)</a>
</div>

<?php if (!$rows) : ?>
    <p class="sma-muted">No published events yet.</p>
<?php endif; ?>
<?php foreach ($rows as $sma_row) :
    $sma_setup_warning = sma_provision_warning($sma_row['event']);
    if ($sma_setup_warning === '') {
        continue;
    } ?>
    <div class="notice notice-warning inline sma-admin__setup-warning" data-event="<?php echo esc_attr($sma_row['event']->post_name); ?>"><p>
        <strong><?php echo esc_html(sma_events_label($sma_row['event']) ?: $sma_row['event']->post_title); ?>:</strong>
        <?php echo esc_html($sma_setup_warning); ?>
        <a href="<?php echo esc_url(get_edit_post_link($sma_row['event']->ID, 'raw')); ?>">Open the event</a>
    </p></div>
<?php endforeach; ?>
<?php
// Bookings left behind by event changes, for events in any status (unpublished ones aren't in $rows).
foreach (sma_events_with_booking_alerts() as $sma_alert_event) :
    foreach (sma_event_booking_warnings($sma_alert_event) as $sma_alert) : ?>
    <div class="notice notice-warning inline sma-admin__booking-warning" data-booking-warning="1" data-event="<?php echo esc_attr($sma_alert_event->post_name); ?>"><p>
        <strong><?php echo esc_html(sma_events_label($sma_alert_event) ?: $sma_alert_event->post_title); ?>:</strong>
        <?php echo esc_html($sma_alert); ?>
        <?php if ($sma_alert_event->post_status !== 'trash') : ?>
            <a href="<?php echo esc_url(get_edit_post_link($sma_alert_event->ID, 'raw')); ?>">Open the event</a>
        <?php endif; ?>
    </p></div>
    <?php endforeach;
endforeach; ?>
<table class="sma-table sma-admin__events">
    <thead>
        <tr>
            <th scope="col">Date</th>
            <th scope="col">City</th>
            <th scope="col">Venue</th>
            <th scope="col">Capacity</th>
            <th scope="col">Families</th>
            <th scope="col">Volunteers</th>
            <th scope="col">Links</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row) :
        $d = $row['details'];
        $slug = $row['event']->post_name;
        $family_total = array_sum($row['families']) + (int) $row['unstaged'];
        ?>
        <tr data-event="<?php echo esc_attr($slug); ?>" class="<?php echo $row['past'] ? 'sma-admin__past' : ''; ?>">
            <td data-label="Date">
                <?php echo esc_html($d['event_date']); ?>
                <?php if ($row['past']) : ?><span class="sma-muted"> (past)</span><?php endif; ?>
            </td>
            <td data-label="City"><?php echo esc_html($d['city']); ?></td>
            <td data-label="Venue"><?php echo esc_html($d['venue']); ?></td>
            <td data-label="Capacity"><?php echo $row['capacity'] ? esc_html((string) $row['capacity']) : '<span class="sma-muted">-</span>'; ?></td>
            <td data-label="Families">
                <div class="sma-admin__badges" data-total="<?php echo esc_attr((string) $family_total); ?>">
                    <?php foreach ($row['families'] as $stage => $n) : ?>
                        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug, 'stage' => $stage])); ?>"><?php echo sma_dash_badge($stage, $n); // escaped in sma_dash_badge ?></a>
                    <?php endforeach; ?>
                    <?php if ($row['unstaged']) : ?>
                        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug, 'stage' => 'none'])); ?>"><?php echo sma_dash_badge('none', $row['unstaged']); ?></a>
                    <?php else : ?>
                        <span hidden><?php echo sma_dash_badge('none', 0); ?></span>
                    <?php endif; ?>
                </div>
                <?php sma_dash_include('waiting-nudge', ['waiting' => $waiting, 'slug' => $slug]); ?>
            </td>
            <td data-label="Volunteers">
                <div class="sma-admin__badges">
                    <?php echo sma_dash_badge('volunteer-applied', $row['volunteers']['volunteer-applied'] ?? 0, 'Applied'); ?>
                    <?php echo sma_dash_badge('volunteer-approved', $row['volunteers']['volunteer-approved'] ?? 0, 'Approved'); ?>
                </div>
            </td>
            <td data-label="Links">
                <ul class="sma-admin__links">
                    <li><a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug])); ?>">Families</a></li>
                    <li><a href="<?php echo esc_url(sma_dash_url(['view' => 'volunteers', 'event' => $slug])); ?>">Volunteers</a></li>
                    <li><a href="<?php echo esc_url(get_permalink($row['event'])); ?>">Public page</a></li>
                    <li><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export&event=' . rawurlencode($slug)), 'sma_export')); ?>">CSV</a></li>
                </ul>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php
    $ne_slug  = SMA_STAGES_NO_EVENT;
    $ne_total = array_sum($no_event['families']) + (int) $no_event['unstaged'];
    ?>
        <tr data-event="<?php echo esc_attr($ne_slug); ?>" class="sma-admin__no-event-row">
            <td data-label="Date"><span class="sma-muted">-</span></td>
            <td data-label="City" colspan="3">Individual session / no event</td>
            <td data-label="Families">
                <div class="sma-admin__badges" data-total="<?php echo esc_attr((string) $ne_total); ?>">
                    <?php foreach ($no_event['families'] as $stage => $n) : ?>
                        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $ne_slug, 'stage' => $stage])); ?>"><?php echo sma_dash_badge($stage, $n); // escaped in sma_dash_badge ?></a>
                    <?php endforeach; ?>
                    <?php if ($no_event['unstaged']) : ?>
                        <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $ne_slug, 'stage' => 'none'])); ?>"><?php echo sma_dash_badge('none', $no_event['unstaged']); // escaped in sma_dash_badge ?></a>
                    <?php endif; ?>
                </div>
                <?php sma_dash_include('waiting-nudge', ['waiting' => $waiting, 'slug' => $ne_slug]); ?>
            </td>
            <td data-label="Volunteers"><?php echo esc_html((string) (int) $no_event['volunteers']); ?> with no date</td>
            <td data-label="Links">
                <ul class="sma-admin__links">
                    <li><a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $ne_slug])); ?>">Families</a></li>
                    <li><a href="<?php echo esc_url(sma_dash_url(['view' => 'volunteers', 'event' => $ne_slug])); ?>">Volunteers</a></li>
                    <li><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export&event=' . rawurlencode($ne_slug)), 'sma_export')); ?>">CSV</a></li>
                </ul>
            </td>
        </tr>
    </tbody>
</table>
