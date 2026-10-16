<?php
// The /events/ list of upcoming published events, used only while the site has no /events/ page of its own.

if (!defined('ABSPATH')) {
    exit;
}

$sma_events = sma_events_open();

sma_pages_part('open');
?>
<header class="sma-event__hero">
    <span class="sma-eyebrow">So Many Angels</span>
    <h1>Upcoming events</h1>
    <p class="sma-event__lead">Find a photo day near you and sign your child up. There is no charge for families.</p>
</header>

<?php sma_pages_part('event-list', ['events' => $sma_events, 'level' => 2, 'id_prefix' => 'sma-event-']); ?>

<div class="sma-events-sponsor">
    <?php sma_pages_part('sponsor'); ?>
</div>
<?php
sma_pages_part('close');
