<?php
/** Replica of Solace's default page wrapper without the title block, used when sma_replica_template is "default". */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="main-page main-page1 main-singular">
    <section class="container-page">
        <div class="myrow row1">
            <div class="content">
                <?php while (have_posts()) : the_post(); ?>
                    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                        <div class="boxes-content ">
                            <div class="the-content">
                                <?php the_content(); ?>
                            </div>
                        </div>
                    </article><!-- #post-<?php the_ID(); ?> -->
                <?php endwhile; ?>
            </div>
        </div>
    </section>
</main><!-- #main -->
<?php
get_footer();
