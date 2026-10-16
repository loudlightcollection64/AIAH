<?php
/** Links between the Follow-ups lists. In scope: $current ('followups' or 'canvases'). */

if (!defined('ABSPATH')) {
    exit;
}

$sma_lists = ['followups' => 'Needs follow-up', 'canvases' => 'Canvases'];
?>
<nav class="sma-admin__filters sma-admin__subnav" aria-label="Follow-up lists">
    <?php foreach ($sma_lists as $sma_view => $sma_label) : ?>
        <a href="<?php echo esc_url(sma_dash_url(['view' => $sma_view])); ?>" data-list="<?php echo esc_attr($sma_view); ?>"<?php echo $current === $sma_view ? ' aria-current="page" class="is-current"' : ''; ?>><?php echo esc_html($sma_label); ?></a>
    <?php endforeach; ?>
</nav>
