<?php

/**
 * Template Name: bbPress - Statistics
 *
 * @package bbPress
 * @subpackage Themes
 */

/** Get the statistics and extract them for later use in this template */
extract( bbp_get_statistics(), EXTR_SKIP );

?>

<?php get_header(); ?>

		<div id="container">
			<div id="content" role="main">

				<?php do_action( 'bbp_template_notices' ); ?>

				<?php while ( have_posts() ) : the_post(); ?>

					<div id="bbp-statistics" class="bbp-statistics">
						<h1 class="entry-title"><?php bbp_title_breadcrumb(); ?></h1>
						<div class="entry-content">

							<?php get_the_content() ? the_content() : _e( '<p>Here are the statistics and popular topics of our forums.</p>', 'bbpress' ); ?>

							<dl role="main">

								<dt><?php _e( 'Registered Users', 'bbpress' ); ?></dt>
								<dd>
									<strong><?php echo $user_count; ?></strong>
								</dd>

								<dt><?php _e( 'Forums', 'bbpress' ); ?></dt>
								<dd>
									<strong><?php echo $forum_count; ?></strong>
								</dd>

								<dt><?php _e( 'Topics', 'bbpress' ); ?></dt>
								<dd>
									<strong><?php echo $topic_count; ?></strong>
								</dd>

								<dt><?php _e( 'Replies', 'bbpress' ); ?></dt>
								<dd>
									<strong><?php echo $reply_count; ?></strong>
								</dd>

								<dt><?php _e( 'Topic Tags', 'bbpress' ); ?></dt>
								<dd>
									<strong><?php echo $topic_tag_count; ?></strong>
								</dd>

								<?php if ( !empty( $empty_topic_tag_count ) ) : ?>

									<dt><?php _e( 'Empty Topic Tags', 'bbpress' ); ?></dt>
									<dd>
										<strong><?php echo $empty_topic_tag_count; ?></strong>
									</dd>

								<?php endif; ?>

								<?php if ( !empty( $hidden_topic_count ) ) : ?>

									<dt><?php _e( 'Hidden Topics', 'bbpress' ); ?></dt>
									<dd>
										<strong>
											<abbr title="<?php echo esc_attr( $hidden_topic_title ); ?>"><?php echo $hidden_topic_count; ?></abbr>
										</strong>
									</dd>

								<?php endif; ?>

								<?php if ( !empty( $hidden_reply_count ) ) : ?>

									<dt><?php _e( 'Hidden Replies', 'bbpress' ); ?></dt>
									<dd>
										<strong>
											<abbr title="<?php echo esc_attr( $hidden_reply_title ); ?>"><?php echo $hidden_reply_count; ?></abbr>
										</strong>
									</dd>

								<?php endif; ?>

								<?php do_action( 'bbp_statistics' ); ?>
							</dl>

							<?php bbp_set_query_name( 'bbp_popular_topics' ); ?>

							<?php if ( bbp_has_topics( array( 'meta_key' => '_bbp_topic_reply_count', 'posts_per_page' => 15, 'max_num_pages' => 1, 'orderby' => 'meta_value_num', 'ignore_sticky_topics' => true ) ) ) : ?>

								<h2 class="entry-title"><?php _e( 'Popular Topics', 'bbpress' ); ?></h2>

								<?php get_template_part( 'loop', 'bbp_topics' ); ?>

							<?php endif; ?>

							<?php bbp_reset_query_name(); ?>

						</div>
					</div><!-- #bbp-statistics -->

				<?php endwhile; ?>

			</div><!-- #content -->
		</div><!-- #container -->

<?php get_sidebar(); ?>
<?php get_footer(); ?>
