<?php
// Opens a plugin page in the theme's chrome: get_header() for classic themes, the "header" template part for block themes.

if (!defined('ABSPATH')) {
    exit;
}

if (!sma_pages_block_theme()) {
    get_header();
} else {
    ob_start();
    block_template_part('header');
    $sma_theme_header = ob_get_clean();
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<header class="wp-block-template-part"><?php echo $sma_theme_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup from the theme ?></header>
<?php
}
?>
<div class="sma sma-page">
<main id="sma-main" class="sma-container">
