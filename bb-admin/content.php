<?php require_once('admin.php'); ?>
<?php bb_get_admin_header(); ?>

<?php
	if ( !bb_current_user_can('browse_deleted') )
		die(__("Now how'd you get here?  And what did you think you'd being doing?")); //This should never happen.
	add_filter( 'topic_link', 'bb_make_link_view_all' );
	add_filter( 'topic_last_post_link', 'bb_make_link_view_all' );
	$topic_query_vars = array('topic_status' => 1, 'open' => 'all', 'count' => true);
	if ( isset($_REQUEST['search']) && $_REQUEST['search'] )
		$topic_query_vars['post_status'] = 'all';
	$topic_query = new BB_Query_Form( 'topic', $topic_query_vars );
	$topics = $topic_query->results;
?>

<div class="wrap">

<h2><?php
$h2_search = $topic_query->get( 'search' );
$h2_forum  = $topic_query->get( 'forum_id' );
$h2_tag    = $topic_query->get( 'tag_id' );
$h2_author = $topic_query->get( 'topic_author_id' );
$h2_status = $topic_query->get( 'topic_status' );
$h2_open   = $topic_query->get( 'open' );

$h2_search = $h2_search ? ' ' . sprintf( __('matching &#8220;%s&#8221;'), wp_specialchars( $h2_search ) ) : '';
$h2_forum  = $h2_forum  ? ' ' . sprintf( __('in &#8220;%s&#8221;')      , get_forum_name( $h2_forum ) ) : '';
$h2_tag    = $h2_tag    ? ' ' . sprintf( __('with tag &#8220;%s&#8221;'), wp_specialchars( bb_get_tag_name( $h2_tag ) ) ) : '';
$h2_author = $h2_author ? ' ' . sprintf( __('by %s')                    , wp_specialchars( get_user_name( $h2_author ) ) ) : '';

$topic_stati = array( 0 => __('Normal') . ' ', 1 => __('Deleted') . ' ', 'all' => '' );
$topic_open  = array( 0 => __('Closed') . ' ', 1 => __('Open') . ' '   , 'all' => '' );

if ( 'all' == $h2_status && 'all' == $h2_open )
	$h2_noun = __('Topics');
else
	$h2_noun = sprintf( __( '%1$s%2$stopics'), $topic_stati[$h2_status], $topic_open[$h2_open] );

printf( __( '%1$s%2$s%3$s%4$s%5$s' ), $h2_noun, $h2_search, $h2_forum, $h2_tag, $h2_author );

?></h2>

<?php $topic_query->form( array('tag' => true, 'topic_author' => true, 'topic_status' => true, 'open' => true, 'submit' => __('Filter &#187;')) ); ?>

<br class="clear" />

<table id="topics-list" class="widefat">
<thead>
<tr>
	<th scope="col"><?php _e('Topic') ?></th>
	<th scope="col"><?php _e('Author') ?></th>
	<th scope="col"><?php _e('Posts') ?></th>
	<th scope="col"><?php _e('Date') ?></th>
	<th scope="col"><?php _e('Freshness') ?></th>
</tr>
</thead>

<tbody>
<?php if ( $topics ) : bb_cache_first_posts( $topics ); foreach ( $topics as $topic ) : $first_post = bb_get_first_post( $topic ); ?>
<tr id="topic-<?php echo $topic->topic_id; ?>"<?php topic_class(); ?>>
	<td class="topic">
		<strong class="row-title"><?php bb_topic_labels(); ?> <a href="<?php topic_link(); ?>"><?php topic_title(); ?></a></strong>
		<p class="row-actions">
			<a href="<?php topic_link(); ?>"><?php _e( 'View' ); ?></a> |
			<?php post_edit_link( $first_post->post_id ); ?> |
			<?php topic_delete_link( array( 'id' => $topic->topic_id, 'before' => '', 'after' => '', 'delete_text' => __( 'Delete' ), 'undelete_text' => __( 'Undelete' ) ) ); ?>
		</p>
	</td>
	<td class="author">
		<a class="author-link" href="<?php user_profile_link( $topic->topic_poster ); ?>">
			<?php echo bb_get_avatar( $topic->topic_poster, '32' ); ?>
			<?php topic_author(); ?><br />
			<?php user_type( $topic->topic_poster ); ?>
		</a>

		<p class="author-data">
		<?php if ( bb_current_user_can( 'edit_users' ) ) : ?>
			<a href="<?php echo clean_url( 'mailto:' . bb_get_user_email( $topic->topic_poster ) ); ?>"><?php echo wp_specialchars( bb_get_user_email( $topic->topic_poster ) ); ?></a><br />
		<?php endif; ?>
			<?php post_ip_link( $first_post->post_id ); ?>
		</p>
	</td>
	<td class="posts num"><?php echo strip_tags( get_topic_posts_link() ); ?></td>
	<td class="date num"><?php topic_start_time( bb_get_datetime_formatstring_i18n() ); ?></td>
	<td class="freshness num"><a href="<?php topic_last_post_link(); ?>" title="<?php echo attribute_escape( sprintf( __( 'Last post by %s' ), get_topic_last_poster() ) ); ?>"><?php topic_time( bb_get_datetime_formatstring_i18n() ); ?></a></td>
</tr>
<?php endforeach; else : ?>
<tr>
	<td colspan="5"><?php _e('No topics found'); ?></td>
</tr>
<?php endif; ?>
</tbody>
</table>

<?php echo get_page_number_links( $page, $topic_query->found_rows ); ?>

</div>

<?php bb_get_admin_footer(); ?>
