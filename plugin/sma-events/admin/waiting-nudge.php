<?php
/** Approval nudge link for one event, e.g. "2 waiting over 3 days". In scope: $waiting, $slug. */

if (!defined('ABSPATH')) {
    exit;
}

$sma_nudge = isset($waiting['events'][$slug]) ? $waiting['events'][$slug] : null;
if (!$sma_nudge) {
    return;
}
$sma_days = (int) $waiting['days'];
?>
<a class="sma-admin__nudge" data-waiting="<?php echo esc_attr((string) (int) $sma_nudge['count']); ?>" data-nudge-days="<?php echo esc_attr((string) $sma_days); ?>" href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $slug, 'stage' => 'awaiting-approval'])); ?>"><?php
    echo esc_html((int) $sma_nudge['count'] . ' waiting over ' . $sma_days . ($sma_days === 1 ? ' day' : ' days'));
?></a>
