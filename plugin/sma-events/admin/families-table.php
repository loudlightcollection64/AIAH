<?php
// Families table, one row per child, allow-listed fields only; Approve, Decline and Thank open the preview.

if (!defined('ABSPATH')) {
    exit;
}

$bulk_form   = isset($bulk_form) ? (string) $bulk_form : '';
$flag_labels = sma_entry_flag_labels();
$consents    = [
    'likeness_release' => 'Likeness release',
    'social_media_ok'  => 'Social media',
    'story_ok'         => 'Story',
];
?>
<table class="sma-table sma-admin__families">
    <caption><?php echo esc_html($caption); ?></caption>
    <thead>
        <tr>
            <th scope="col">Guardian</th>
            <th scope="col">Contact</th>
            <th scope="col">Child</th>
            <th scope="col">Costume / theme</th>
            <th scope="col">Consents</th>
            <th scope="col">Stage</th>
            <th scope="col">Booking</th>
            <th scope="col">Last decision</th>
            <th scope="col">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($families as $f) :
        $cid      = (int) $f['contact_id'];
        $eid      = (int) $f['entry_id'];
        $row_id   = $cid . '-' . $eid . '-' . $f['event_slug'];
        $name     = trim($f['first_name'] . ' ' . $f['last_name']);
        $children = (int) $f['children'];
        $child    = $f['child_first_name'];
        if ($f['age'] !== '') {
            $child .= ($child !== '' ? ', ' : '') . 'age ' . $f['age'];
        }
        $no_event    = !empty($f['no_event']);
        $can_approve = sma_can_approve($f['stage'], $f['event_slug']);
        $can_decline = sma_can_decline($f['stage']);
        $applies     = $children > 1 ? 'Applies to all ' . $children . ' children' : '';
        ?>
        <tr data-contact="<?php echo esc_attr((string) $cid); ?>" data-entry="<?php echo esc_attr((string) $eid); ?>" data-stage="<?php echo esc_attr($f['stage'] === '' ? 'none' : $f['stage']); ?>" data-children="<?php echo esc_attr((string) $children); ?>" data-flags="<?php echo esc_attr(implode(' ', $f['flags'])); ?>"<?php
            if (isset($f['waiting_days']) && $f['waiting_days'] !== null) {
                echo ' data-waiting-days="' . esc_attr((string) (int) $f['waiting_days']) . '"';
            } ?>>
            <td data-label="Guardian"><?php echo esc_html($name !== '' ? $name : '(no name)'); ?></td>
            <td data-label="Contact">
                <a href="<?php echo esc_url('mailto:' . $f['email']); ?>"><?php echo esc_html($f['email']); ?></a>
                <?php if ($f['phone'] !== '') : ?><br><span class="sma-muted"><?php echo esc_html($f['phone']); ?></span><?php endif; ?>
            </td>
            <td data-label="Child">
                <?php echo $child !== '' ? esc_html($child) : '<span class="sma-muted">-</span>'; ?>
                <?php if ($f['flash_sensitive'] === 'yes') : ?><br><span class="sma-admin__flag">Flash sensitive</span><?php endif; ?>
                <?php foreach ($f['flags'] as $flag) : ?>
                    <br><span class="sma-admin__flag sma-admin__flag--<?php echo esc_attr(sanitize_html_class($flag)); ?>" data-flag="<?php echo esc_attr($flag); ?>"><?php echo esc_html(isset($flag_labels[$flag]) ? $flag_labels[$flag] : $flag); ?></span>
                <?php endforeach; ?>
                <?php if ($children > 1) : ?><br><span class="sma-muted sma-admin__siblings"><?php echo esc_html($children . ' children'); ?></span><?php endif; ?>
                <?php if ($no_event && $f['choice'] !== '') : ?><br><span class="sma-muted sma-admin__choice">Chose: <?php echo esc_html($f['choice']); ?></span><?php endif; ?>
            </td>
            <td data-label="Costume / theme"><?php echo $f['costume_theme'] !== '' ? esc_html($f['costume_theme']) : '<span class="sma-muted">-</span>'; ?></td>
            <td data-label="Consents">
                <?php if (!$eid) : ?>
                    <span class="sma-muted">-</span>
                <?php else :
                    foreach ($consents as $key => $label) : ?>
                        <span class="sma-admin__consent" data-consent="<?php echo esc_attr($key); ?>" data-value="<?php echo esc_attr($f[$key]); ?>"><?php echo esc_html($label . ': ' . $f[$key]); ?></span><br>
                    <?php endforeach;
                endif; ?>
            </td>
            <td data-label="Stage">
                <?php echo sma_dash_badge($f['stage']); // escaped in sma_dash_badge ?>
                <?php if (!empty($f['waiting_too_long'])) :
                    $sma_wait = (int) $f['waiting_days']; ?>
                    <br><span class="sma-admin__flag sma-admin__waiting" data-waiting-too-long="1"><?php echo esc_html('Waiting ' . $sma_wait . ($sma_wait === 1 ? ' day' : ' days')); ?></span>
                <?php endif; ?>
                <?php if ($bulk_form !== '' && in_array($f['stage'], SMA_DASH_BULK_ATTENDED_FROM, true)) : ?>
                    <br><label class="sma-admin__bulk-pick"><input type="checkbox" name="contact_ids[]" value="<?php echo esc_attr((string) $cid); ?>" form="<?php echo esc_attr($bulk_form); ?>" data-bulk-attended="<?php echo esc_attr((string) $cid); ?>"> Attended</label>
                <?php endif; ?>
                <?php if (is_array($f['canvas'] ?? null)) : ?>
                    <br><?php sma_dash_canvas_controls($cid, $f['event_slug'], $f['canvas'], $return_view, $stage_filter); ?>
                <?php endif; ?>
            </td>
            <td data-label="Booking">
                <?php echo $f['booking_start'] !== '' ? esc_html($f['booking_start']) : '<span class="sma-muted">-</span>'; ?>
                <?php if (is_array($f['reminders'] ?? null)) : ?>
                    <?php if ($f['reminders']) : ?>
                        <br><span class="sma-admin__reminders" data-reminders="<?php echo esc_attr((string) count($f['reminders'])); ?>">Reminders: <?php echo esc_html(implode(' · ', $f['reminders'])); ?></span>
                    <?php else : ?>
                        <br><span class="sma-admin__flag" data-reminders="0">No reminders scheduled</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td data-label="Last decision">
                <?php echo $f['decision_label'] !== '' ? esc_html($f['decision_label']) : '<span class="sma-muted">-</span>'; ?>
                <?php if (!empty($f['decision']['detail'])) : ?><br><span class="sma-muted"><?php echo esc_html($f['decision']['detail']); ?></span><?php endif; ?>
                <?php if (!empty($f['draft'])) : ?>
                    <details class="sma-admin__draft" data-draft="1">
                        <summary>Decline email draft (not sent)</summary>
                        <p class="sma-muted">Copy, edit and send it yourself if you want to. Nothing was sent.</p>
                        <p><strong>Subject:</strong> <?php echo esc_html($f['draft']['subject']); ?></p>
                        <pre class="sma-admin__draft-body"><?php echo esc_html($f['draft']['body']); ?></pre>
                        <?php if (!empty($f['draft']['reason'])) : ?>
                            <p class="sma-muted">Internal reason: <?php echo esc_html($f['draft']['reason']); ?></p>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </td>
            <td data-label="Actions">
                <div class="sma-admin__actions">
                <?php if ($no_event) : ?>
                    <span class="sma-muted sma-admin__no-event" data-no-event="<?php echo esc_attr($f['event_slug']); ?>">No event selected</span>
                <?php endif; ?>
                <?php if ($applies !== '' && ($can_approve || $can_decline)) : ?>
                    <span class="sma-muted sma-admin__applies"><?php echo esc_html($applies); ?></span>
                <?php endif; ?>
                <?php
                // Approve, Decline and Thank open a preview of the email; only its confirm sends.
                $links = [];
                if ($can_approve) {
                    $links['approve'] = ['Approve…', 'sma-btn--primary'];
                }
                if ($can_decline) {
                    $links['decline'] = ['Decline…', 'sma-btn--secondary'];
                }
                if ($f['stage'] === 'attended' && !$no_event) {
                    $links['thank'] = [$f['thanked_at'] !== '' ? 'Thank again…' : 'Send thank-you…', 'sma-btn--secondary'];
                }
                if ($f['thanked_at'] !== '') : ?>
                    <span class="sma-muted sma-admin__thanked" data-thanked="1"><?php echo esc_html('Thanked ' . substr($f['thanked_at'], 0, 10)); ?></span>
                <?php endif;
                foreach ($links as $decision => $link) : ?>
                    <a class="sma-btn <?php echo esc_attr($link[1]); ?> sma-admin__preview-link" data-contact="<?php echo esc_attr((string) $cid); ?>" data-decision="<?php echo esc_attr($decision); ?>" data-preview="1" href="<?php echo esc_url(sma_dash_preview_url($decision, $cid, $f['event_slug'], $return_view, $stage_filter)); ?>"><?php echo esc_html($link[0]); ?></a>
                <?php endforeach;
                sma_dash_action_form(
                    'sma_family_stage',
                    $cid,
                    sma_dash_return_fields($return_view, $f['event_slug'], $stage_filter),
                    ['Set stage', 'button'],
                    [
                        'decision' => 'stage',
                        'class'    => 'sma-admin__form--stage',
                        'controls' => function () use ($row_id, $f) { ?>
                            <label class="sma-visually-hidden" for="sma-stage-<?php echo esc_attr($row_id); ?>">Set stage</label>
                            <select name="new_stage" id="sma-stage-<?php echo esc_attr($row_id); ?>" required>
                                <?php // Shows the current stage; a stage that can't be set here (booked, approved, none) gets a prompt instead of defaulting to the first option. ?>
                                <option value="" disabled <?php selected(!in_array($f['stage'], SMA_DASH_OVERRIDE_STAGES, true)); ?>>Change stage to…</option>
                                <?php foreach (SMA_DASH_OVERRIDE_STAGES as $opt) : ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($f['stage'], $opt); ?>><?php echo esc_html(sma_dash_stage_label($opt)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php },
                    ]
                ); ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
