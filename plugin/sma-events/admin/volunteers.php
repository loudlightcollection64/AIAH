<?php
// Volunteers for all events or one ($event_slug, $volunteers, $events for the filter).

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sma-admin__toolbar">
    <h2 class="sma-admin__heading">Volunteers</h2>
    <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sma_export_volunteers&event=' . rawurlencode($event_slug !== '' ? $event_slug : 'all')), 'sma_export')); ?>">Export volunteers (CSV)</a>
</div>

<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="sma-admin__filters">
    <input type="hidden" name="page" value="<?php echo esc_attr(SMA_DASH_SLUG); ?>">
    <input type="hidden" name="view" value="volunteers">
    <label for="sma-vol-event">Event</label>
    <select name="event" id="sma-vol-event">
        <option value="">All events</option>
        <?php foreach ($events as $ev) : ?>
            <option value="<?php echo esc_attr($ev->post_name); ?>" <?php selected($event_slug, $ev->post_name); ?>><?php echo esc_html(sma_events_label($ev)); ?></option>
        <?php endforeach; ?>
        <option value="<?php echo esc_attr(SMA_STAGES_NO_EVENT); ?>" <?php selected($event_slug, SMA_STAGES_NO_EVENT); ?>>No specific date</option>
    </select>
    <button type="submit" class="button">Show</button>
</form>

<?php if (!$volunteers) : ?>
    <p class="sma-muted">No volunteers yet.</p>
<?php else : ?>
<table class="sma-table sma-admin__volunteers">
    <caption><?php echo esc_html('Volunteers: ' . count($volunteers)); ?></caption>
    <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Event</th>
            <th scope="col">Role</th>
            <th scope="col">Location</th>
            <th scope="col">Portfolio</th>
            <th scope="col">Stage</th>
            <th scope="col">Applied</th>
            <th scope="col">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($volunteers as $v) :
        $cid = (int) $v['contact_id'];
        $portfolio = $v['portfolio_url'] !== '' ? esc_url($v['portfolio_url'], ['http', 'https']) : '';
        ?>
        <tr data-contact="<?php echo esc_attr((string) $cid); ?>" data-stage="<?php echo esc_attr($v['stage'] !== '' ? $v['stage'] : 'none'); ?>">
            <td data-label="Name">
                <?php echo esc_html(trim($v['first_name'] . ' ' . $v['last_name'])); ?><br>
                <a href="<?php echo esc_url('mailto:' . $v['email']); ?>"><?php echo esc_html($v['email']); ?></a>
            </td>
            <td data-label="Event"><a href="<?php echo esc_url(sma_dash_url(['view' => 'volunteers', 'event' => $v['event_slug']])); ?>"><?php echo esc_html($v['no_event'] ? 'No specific date' : $v['event_slug']); ?></a></td>
            <td data-label="Role"><?php echo esc_html($v['role']); ?></td>
            <td data-label="Location"><?php echo esc_html($v['preferred_location']); ?></td>
            <td data-label="Portfolio">
                <?php if ($portfolio !== '') : ?>
                    <a href="<?php echo $portfolio; // escaped above with esc_url ?>" target="_blank" rel="noopener noreferrer">Open</a>
                <?php else : ?>
                    <span class="sma-muted">-</span>
                <?php endif; ?>
            </td>
            <td data-label="Stage"><?php echo sma_dash_badge($v['stage']); // escaped in sma_dash_badge ?></td>
            <td data-label="Applied"><?php echo esc_html($v['applied_at']); ?></td>
            <td data-label="Actions">
                <div class="sma-admin__actions">
                <?php if ($v['no_event']) : ?>
                    <span class="sma-muted sma-admin__no-event">No date chosen: approve once they pick an event</span>
                <?php endif; ?>
                <?php
                $sma_vol_fields = ['event' => $v['event_slug'], 'event_filter' => $event_slug];
                if (!$v['no_event'] && $v['stage'] !== 'volunteer-approved') {
                    // A preview of the confirmation email; only its confirm approves and sends. ?>
                    <a class="sma-btn sma-btn--primary sma-admin__preview-link" data-contact="<?php echo esc_attr((string) $cid); ?>" data-decision="approve-volunteer" data-preview="1" href="<?php echo esc_url(sma_dash_preview_url('approve-volunteer', $cid, $v['event_slug'], 'volunteers', $event_slug)); ?>">Approve…</a>
                    <?php
                }
                if (!$v['no_event'] && $v['stage'] !== 'volunteer-declined') {
                    $sma_reason_id = 'sma-vreason-' . $cid . '-' . $v['event_slug'];
                    sma_dash_action_form(
                        'sma_volunteer_decision',
                        $cid,
                        ['decision' => 'decline'] + $sma_vol_fields,
                        ['Decline', 'sma-btn sma-btn--secondary'],
                        [
                            'decision' => 'decline',
                            'controls' => function () use ($sma_reason_id) { ?>
                                <label class="sma-visually-hidden" for="<?php echo esc_attr($sma_reason_id); ?>">Reason (internal, optional)</label>
                                <input type="text" name="reason" id="<?php echo esc_attr($sma_reason_id); ?>" class="sma-admin__reason" placeholder="Reason (internal)" maxlength="300">
                            <?php },
                        ]
                    );
                }
                ?>
                <?php if (!$v['no_event'] && $v['stage'] === 'volunteer-approved') : ?>
                    <?php if ($v['thanked_at'] !== '') : ?>
                        <span class="sma-muted sma-admin__thanked" data-thanked="1"><?php echo esc_html('Thanked ' . substr($v['thanked_at'], 0, 10)); ?></span>
                    <?php endif; ?>
                    <?php // A preview of the thank-you email; only its confirm sends. ?>
                    <a class="sma-btn sma-btn--secondary sma-admin__preview-link" data-contact="<?php echo esc_attr((string) $cid); ?>" data-decision="thank-volunteer" data-preview="1" href="<?php echo esc_url(sma_dash_preview_url('thank-volunteer', $cid, $v['event_slug'], 'volunteers', $event_slug)); ?>"><?php echo esc_html($v['thanked_at'] !== '' ? 'Thank again…' : 'Thank…'); ?></a>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
