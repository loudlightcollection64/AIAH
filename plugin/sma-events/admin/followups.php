<?php
// Every family needing follow-up, grouped by event ($groups: slug, title, families), one row per child.

if (!defined('ABSPATH')) {
    exit;
}

sma_dash_include('followups-nav', ['current' => 'followups']);
?>
<h2 class="sma-admin__heading">Needs follow-up</h2>

<?php if (!$groups) : ?>
    <p class="sma-muted">Nobody needs a follow-up right now.</p>
<?php else :
    foreach ($groups as $group) :
        ?>
        <section class="sma-admin__group" data-event="<?php echo esc_attr($group['slug']); ?>">
            <h3>
                <a href="<?php echo esc_url(sma_dash_url(['view' => 'event', 'event' => $group['slug']])); ?>"><?php echo esc_html($group['title']); ?></a>
            </h3>
            <?php
            sma_dash_include('families-table', [
                'families'     => $group['families'],
                'return_view'  => 'followups',
                'stage_filter' => '',
                'caption'      => 'Needs follow-up (children): ' . count($group['families']),
            ]);
            ?>
        </section>
    <?php endforeach;
endif; ?>
