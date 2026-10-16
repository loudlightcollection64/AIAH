<?php
// Event cards or the "no upcoming events" panel ($events, $level for card headings, $id_prefix unique per list).

if (!defined('ABSPATH')) {
    exit;
}

$sma_events = isset($events) && is_array($events) ? $events : [];
$sma_level  = (isset($level) && (int) $level === 3) ? 3 : 2;
$sma_htag   = 'h' . $sma_level;
$sma_prefix = isset($id_prefix) ? sanitize_html_class((string) $id_prefix) : 'sma-event-';
?>
<?php if ($sma_events) : ?>
    <ul class="sma-grid sma-event-list" aria-label="Upcoming events">
        <?php foreach ($sma_events as $sma_event) :
            $sma_city     = trim((string) get_post_meta($sma_event->ID, 'city', true));
            $sma_name     = $sma_city !== '' ? $sma_city : get_the_title($sma_event);
            $sma_link     = get_permalink($sma_event);
            $sma_when     = sma_event_when($sma_event);
            $sma_label    = trim($sma_name . ($sma_when['date_label'] !== '' ? ', ' . $sma_when['date_label'] : ''));
            $sma_title_id = $sma_prefix . (int) $sma_event->ID;
            $sma_passed   = sma_event_is_past($sma_event);
            ?>
            <li>
                <article class="<?php echo $sma_passed ? 'sma-card sma-card--passed' : 'sma-card'; ?>" aria-labelledby="<?php echo esc_attr($sma_title_id); ?>">
                    <div class="sma-card__body">
                        <<?php echo $sma_htag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h2 or h3, fixed above ?> id="<?php echo esc_attr($sma_title_id); ?>" class="sma-card__title">
                            <a href="<?php echo esc_url($sma_link); ?>"><?php echo esc_html($sma_name); ?></a>
                        </<?php echo $sma_htag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h2 or h3, fixed above ?>>
                        <?php sma_pages_part('event-meta', ['event' => $sma_event, 'compact' => true]); ?>
                        <div class="sma-card__actions">
                            <?php if ($sma_passed) : ?>
                                <span class="sma-muted">This event has passed</span>
                            <?php else : ?>
                                <a class="sma-btn sma-btn--primary" href="<?php echo esc_url($sma_link . '#sign-up'); ?>" aria-label="<?php echo esc_attr('Sign up for ' . $sma_label); ?>">Sign up</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else : ?>
    <section class="sma-panel sma-empty" aria-labelledby="<?php echo esc_attr($sma_prefix . 'empty'); ?>">
        <<?php echo $sma_htag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h2 or h3, fixed above ?> id="<?php echo esc_attr($sma_prefix . 'empty'); ?>">No upcoming events just yet</<?php echo $sma_htag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h2 or h3, fixed above ?>>
        <p>There are no photo days on the calendar right now. Check back soon, or email us at
        <a href="<?php echo esc_url('mailto:' . sma_contact_email()); ?>"><?php echo esc_html(sma_contact_email()); ?></a>.</p>
    </section>
<?php endif; ?>
