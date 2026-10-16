<?php
// Closes a plugin page opened with parts/open.php.

if (!defined('ABSPATH')) {
    exit;
}
?>
</main>
</div>
<?php
if (!sma_pages_block_theme()) {
    get_footer();
} else {
    ?>
<footer class="wp-block-template-part"><?php block_template_part('footer'); ?></footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
}
