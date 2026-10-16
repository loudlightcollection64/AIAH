<?php
// Sponsor (Givebutter) and volunteer links, worded as on the live event pages.

if (!defined('ABSPATH')) {
    exit;
}

$sma_give      = sma_givebutter_url();
$sma_volunteer = sma_volunteer_page_url();
?>
<section class="sma-card sma-sponsor" aria-labelledby="sma-sponsor-title">
    <div class="sma-card__body">
        <h2 id="sma-sponsor-title" class="sma-card__title">Help sponsor a photo session</h2>
        <p>Photo sessions are free for families. If you&rsquo;d like to help cover one, you can sponsor a session through Givebutter.</p>
        <div class="sma-card__actions">
            <?php if ($sma_give !== '') : ?>
                <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url($sma_give); ?>">Sponsor a session</a>
            <?php endif; ?>
            <?php if ($sma_volunteer !== '') : ?>
                <a class="sma-btn sma-btn--secondary" href="<?php echo esc_url($sma_volunteer); ?>">Volunteer at the event</a>
            <?php endif; ?>
        </div>
    </div>
</section>
