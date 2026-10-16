<?php
// "What to expect" and the costume note, worded as on the live site; the canvas handover comes from the event.

if (!defined('ABSPATH')) {
    exit;
}

$sma_costume_url = sma_costume_help_url();
?>
<section class="sma-panel" aria-labelledby="sma-expect-title">
    <h2 id="sma-expect-title">What to expect</h2>
    <p>A day filled with fun, laughter, professional photography, and the chance to create lasting memories.</p>
    <p>There is no charge for this. Every family receives a photo session and a 16&times;24 printed canvas.</p>
    <p>On photo day, a volunteer will meet you at check-in and walk you to your station. Sit back and let your kid be a kid, that moment&rsquo;s theirs.</p>

    <h3 id="sma-costume-title">A note about costumes</h3>
    <p>You&rsquo;re responsible for bringing your child&rsquo;s costume, and there will be space to change on-site.
    <?php if ($sma_costume_url !== '') : ?>
        If you need help, reach out to <a href="<?php echo esc_url($sma_costume_url); ?>">Costumes for Courage</a> right away with your session date.
    <?php else : ?>
        If you need help, reach out to Costumes for Courage right away with your session date.
    <?php endif; ?>
    </p>
</section>
