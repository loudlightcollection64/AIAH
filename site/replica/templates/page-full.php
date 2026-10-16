<?php
/** Replica of Elementor's "Full Width" template (header, bare page markup, footer), used when sma_replica_template is "full". */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) {
    the_post();
    the_content();
}

get_footer();
