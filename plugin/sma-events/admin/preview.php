<?php
// Preview before anything is sent: who, the exact email, and a confirm form (or why it can't be sent).

if (!defined('ABSPATH')) {
    exit;
}

$post_url = admin_url('admin-post.php');
?>
<p><a href="<?php echo esc_url($cancel_url); ?>">&larr; Back</a></p>

<?php if ($error !== '' || !$preview) : ?>
    <div class="notice notice-error inline sma-admin__preview-error" data-preview-error="1"><p><?php echo esc_html($error !== '' ? $error : 'Nothing to preview.'); ?></p></div>
    <p><a class="sma-btn sma-btn--secondary" href="<?php echo esc_url($cancel_url); ?>">Back</a></p>
<?php else : ?>
<section class="sma-admin__preview" data-preview="<?php echo esc_attr($decision); ?>" data-contact="<?php echo esc_attr((string) (int) $contact_id); ?>" data-event="<?php echo esc_attr($event_slug); ?>">
    <h2 class="sma-admin__heading"><?php echo esc_html($preview['title']); ?></h2>

    <dl class="sma-admin__preview-who">
        <?php foreach ($preview['who'] as $label => $value) : ?>
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo $value !== '' ? esc_html($value) : '<span class="sma-muted">-</span>'; ?></dd>
        <?php endforeach; ?>
    </dl>

    <?php foreach ($preview['groups'] as $group) : ?>
        <div class="sma-admin__preview-group" data-group="<?php echo esc_attr(sanitize_title($group['label'])); ?>" data-count="<?php echo esc_attr((string) (int) $group['count']); ?>">
            <h3><?php echo esc_html($group['label'] . ': ' . (int) $group['count']); ?></h3>
            <?php if ($group['names']) : ?>
                <p><?php echo esc_html(implode(', ', $group['names'])); ?></p>
            <?php else : ?>
                <p class="sma-muted">Nobody to thank here.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($preview['mails'] as $mail) : ?>
        <div class="sma-admin__preview-mail<?php echo $mail['draft'] ? ' sma-admin__preview-mail--draft' : ''; ?>" data-template="<?php echo esc_attr($mail['template']); ?>">
            <h3><?php echo esc_html($mail['heading']); ?></h3>
            <p><strong>Subject:</strong> <span class="sma-admin__preview-subject"><?php echo esc_html($mail['subject']); ?></span></p>
            <pre class="sma-admin__draft-body sma-admin__preview-body"><?php echo esc_html($mail['body']); ?></pre>
        </div>
    <?php endforeach; ?>

    <?php if ($preview['note'] !== '') : ?>
        <p class="sma-admin__preview-note"><strong><?php echo esc_html($preview['note']); ?></strong></p>
    <?php endif; ?>

    <?php if ($preview['blocked'] !== '') : ?>
        <div class="notice notice-warning inline sma-admin__preview-blocked" data-blocked="1"><p><?php echo esc_html($preview['blocked']); ?></p></div>
        <p><a class="sma-btn sma-btn--secondary" href="<?php echo esc_url($cancel_url); ?>">Back</a></p>
    <?php else :
        $form_decision = $decision === 'thank-volunteer' ? 'thank' : ($decision === 'approve-volunteer' ? 'approve' : $decision);
        ?>
        <form method="post" action="<?php echo esc_url($post_url); ?>" class="sma-admin__form sma-admin__preview-form" data-contact="<?php echo esc_attr((string) (int) $contact_id); ?>" data-decision="<?php echo esc_attr($decision); ?>" data-confirm="1">
            <input type="hidden" name="action" value="<?php echo esc_attr($preview['post_action']); ?>">
            <input type="hidden" name="decision" value="<?php echo esc_attr($form_decision); ?>">
            <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) (int) $contact_id); ?>">
            <input type="hidden" name="event" value="<?php echo esc_attr($event_slug); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr($return_view); ?>">
            <input type="hidden" name="stage_filter" value="<?php echo esc_attr($return_view === 'volunteers' ? '' : $stage_filter); ?>">
            <?php if ($return_view === 'volunteers') : ?>
                <input type="hidden" name="event_filter" value="<?php echo esc_attr($stage_filter); ?>">
            <?php endif; ?>
            <input type="hidden" name="confirmed" value="1">
            <?php if ($preview['again']) : ?>
                <input type="hidden" name="again" value="1">
            <?php endif; ?>
            <?php if (!empty($preview['cancels_booking'])) : ?>
                <input type="hidden" name="cancel_bookings" value="1">
            <?php endif; ?>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($preview['nonce']); ?>">
            <?php if ($preview['reason']) : ?>
                <label for="sma-preview-reason">Reason (internal, optional; never sent)</label>
                <input type="text" name="reason" id="sma-preview-reason" class="sma-admin__reason" maxlength="300">
            <?php endif; ?>
            <button type="submit" class="sma-btn <?php echo esc_attr(in_array($decision, ['decline', 'cancel'], true) ? 'sma-btn--secondary' : 'sma-btn--primary'); ?>"><?php echo esc_html($preview['button']); ?></button>
            <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url($cancel_url); ?>" data-cancel="1">Cancel</a>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
