<?php
// One upcoming event: details, what to expect, sponsor link and the sign-up form with the event preselected.

if (!defined('ABSPATH')) {
    exit;
}

$sma_event = get_queried_object();
if (!$sma_event instanceof WP_Post) {
    return;
}

$sma_city    = trim((string) get_post_meta($sma_event->ID, 'city', true));
$sma_venue   = trim((string) get_post_meta($sma_event->ID, 'venue', true));
$sma_when    = sma_event_when($sma_event);
$sma_heading = $sma_city !== '' ? $sma_city : get_the_title($sma_event);
$sma_form    = sma_signup_form_html($sma_event);
$sma_body    = trim((string) $sma_event->post_content);
$sma_email   = sma_contact_email();
$sma_list_url = sma_events_list_url();

sma_pages_part('open');
?>
<article class="sma-event" aria-labelledby="sma-event-title">
    <header class="sma-event__hero">
        <span class="sma-eyebrow">Photo experience</span>
        <h1 id="sma-event-title"><?php echo esc_html($sma_heading); ?></h1>
        <p class="sma-event__lead">
            <?php if ($sma_venue !== '') : ?>Join us at <strong><?php echo esc_html($sma_venue); ?></strong><?php else : ?>Join us<?php endif; ?><?php if ($sma_when['date_label'] !== '') : ?>
            on <time datetime="<?php echo esc_attr($sma_when['date_iso']); ?>"><?php echo esc_html($sma_when['date_label']); ?></time><?php endif; ?>.
        </p>
        <p><a class="sma-btn sma-btn--primary" href="#sign-up">Sign your child up</a></p>
    </header>

    <div class="sma-event__layout">
        <div class="sma-event__main">
            <section class="sma-panel" aria-labelledby="sma-details-title">
                <h2 id="sma-details-title">Event details</h2>
                <?php sma_pages_part('event-meta', ['event' => $sma_event]); ?>
            </section>

            <?php if ($sma_body !== '') : ?>
                <section class="sma-panel sma-event__content">
                    <?php
                    // No the_content: shortcodes in this body must not run, since a restricted account can edit it.
                    echo wpautop(wp_kses_post(strip_shortcodes($sma_body)));
                    ?>
                </section>
            <?php endif; ?>

            <?php sma_pages_part('what-to-expect'); ?>

            <section id="sign-up" class="sma-panel sma-signup" aria-labelledby="sma-signup-title">
                <h2 id="sma-signup-title">Sign your child up for a photo experience!</h2>
                <?php if ($sma_form !== '') : ?>
                    <?php echo $sma_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fluent Forms escapes its own form markup ?>
                <?php else : ?>
                    <p>The sign-up form isn&rsquo;t available right now. Email us at
                    <a href="<?php echo esc_url('mailto:' . $sma_email); ?>"><?php echo esc_html($sma_email); ?></a>
                    and we&rsquo;ll help you sign up.</p>
                <?php endif; ?>
            </section>
        </div>

        <aside class="sma-event__aside" aria-label="Support and more events">
            <?php sma_pages_part('sponsor'); ?>
            <?php if ($sma_list_url !== '') : ?>
                <p class="sma-event__back"><a href="<?php echo esc_url($sma_list_url); ?>">See all upcoming events</a></p>
            <?php endif; ?>
        </aside>
    </div>
</article>
<?php
sma_pages_part('close');
