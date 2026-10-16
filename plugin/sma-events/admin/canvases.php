<?php
/** Follow-ups → Canvases: attended families whose canvas isn't delivered, oldest first. In scope: $rows or null. */

if (!defined('ABSPATH')) {
    exit;
}

sma_dash_include('followups-nav', ['current' => 'canvases']);
?>
<h2 class="sma-admin__heading">Canvases to finish</h2>
<p class="sma-muted">Families who attended, oldest event first, until their canvas is delivered. Recording a step sends nothing to the family.</p>

<?php if ($rows === null) : ?>
    <p class="sma-muted">FluentCRM is not active.</p>
<?php elseif (!$rows) : ?>
    <p class="sma-muted">Every attended family's canvas has been delivered.</p>
<?php else : ?>
<table class="sma-table sma-admin__canvases">
    <caption><?php echo esc_html('Canvases not delivered yet: ' . count($rows)); ?></caption>
    <thead>
        <tr>
            <th scope="col">Event</th>
            <th scope="col">Guardian</th>
            <th scope="col">Children</th>
            <th scope="col">Canvas</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r) :
        $sma_name = trim($r['first_name'] . ' ' . $r['last_name']); ?>
        <tr data-canvas-row="<?php echo esc_attr((string) (int) $r['contact_id']); ?>" data-event="<?php echo esc_attr($r['event_slug']); ?>" data-canvas="<?php echo esc_attr($r['canvas']['status'] === '' ? 'none' : $r['canvas']['status']); ?>">
            <td data-label="Event">
                <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $r['event_slug'], 'stage' => 'attended'])); ?>"><?php echo esc_html($r['event_title']); ?></a>
            </td>
            <td data-label="Guardian">
                <?php echo esc_html($sma_name !== '' ? $sma_name : '(no name)'); ?>
                <br><a href="<?php echo esc_url('mailto:' . $r['email']); ?>"><?php echo esc_html($r['email']); ?></a>
            </td>
            <td data-label="Children"><?php echo $r['children'] ? esc_html(sma_join_names($r['children'])) : '<span class="sma-muted">-</span>'; ?></td>
            <td data-label="Canvas">
                <div class="sma-admin__actions">
                    <?php sma_dash_canvas_controls($r['contact_id'], $r['event_slug'], $r['canvas'], 'canvases'); ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
