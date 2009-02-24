<?php
/**
 * bbPress Forum Content Statistics Functions
 *
 * @package bbPress
 */



/**
 * Get the total number of forums
 *
 * @since 1.0
 * @uses $bbdb Database Object
 * @uses $bb_total_forums Cache of result generated by previous run
 *
 * @return int
 */
function get_total_forums() {
	global $bbdb, $bb_total_forums;
	if ( isset($bb_total_forums) )
		return $bb_total_forums;
	$bb_total_forums = $bbdb->get_var("SELECT COUNT(*) FROM $bbdb->forums");
	return $bb_total_forums;
}

/**
 * Output the number of forums
 *
 * @since 1.0
 */
function total_forums() {
	echo apply_filters('total_forums', get_total_forums() );
}

if ( !function_exists( 'get_total_users' ) ) :
/**
 * Get the total number of users
 *
 * @since 0.7.2
 * @uses $bbdb Database Object
 * @uses $bb_total_users Cache of result generated by previous run
 *
 * @return int
 */
function get_total_users() {
	global $bbdb, $bb_total_users;
	if ( isset($bb_total_users) )
		return $bb_total_users;
	$bb_total_users = $bbdb->get_var("SELECT COUNT(*) FROM $bbdb->users");
	return $bb_total_users;
}
endif;

/**
 * Output the number of users
 *
 * @since 0.7.2
 */
function total_users() {
	echo apply_filters('total_users', get_total_users() );
}

/**
 * Get the total number of posts
 *
 * @since 0.7.2
 * @uses $bbdb Database Object
 * @uses $bb_total_posts Cache of result generated by previous run
 *
 * @return int
 */
function get_total_posts() {
	global $bbdb, $bb_total_posts;
	if ( isset($bb_total_posts) )
		return $bb_total_posts;
	$bb_total_posts = $bbdb->get_var("SELECT SUM(posts) FROM $bbdb->forums");
	return $bb_total_posts;
}

/**
 * Output the number of posts
 *
 * @since 0.7.2
 */
function total_posts() {
	echo apply_filters('total_posts', get_total_posts() );
}

/**
 * Get the total number of topics
 *
 * @since 0.7.2
 * @uses $bbdb Database Object
 * @uses $bb_total_topics Cache of result generated by previous run
 *
 * @return int
 */
function get_total_topics() {
	global $bbdb, $bb_total_topics;
	if ( isset($bb_total_topics) )
		return $bb_total_topics;
	$bb_total_topics = $bbdb->get_var("SELECT SUM(topics) FROM $bbdb->forums");
	return $bb_total_topics;
}

/**
 * Output the number of topics
 *
 * @since 0.7.2
 */
function total_topics() {
	echo apply_filters('total_topics', get_total_topics());
}

/**
 * Get the popular topics
 *
 * @since 0.7.2
 *
 * @param int $num Number of topics to return
 * @return array
 */
function get_popular_topics( $num = 10 ) {
	$query = new BB_Query( 'topic', array('per_page' => $num, 'order_by' => 'topic_posts', 'append_meta' => 0) );
	return $query->results;
}

if ( !function_exists( 'get_recent_registrants' ) ) :
/**
 * Get the data of the latest registrants
 *
 * @since 0.7.2
 * @uses $bbdb Database Object
 *
 * @return array
 */
function get_recent_registrants( $num = 10 ) {
	global $bbdb;
	return bb_append_meta( (array) $bbdb->get_results( $bbdb->prepare(
		"SELECT * FROM $bbdb->users ORDER BY user_registered DESC LIMIT %d",
		$num
	) ), 'user');
}
endif;

/**
 * Output the date when current installation was created
 *
 * @since 0.8
 *
 * @param string|array $args Arguments to pass through to bb_get_inception()
 */
function bb_inception( $args = '' ) {
	$args = _bb_parse_time_function_args( $args );
	$time = apply_filters( 'bb_inception', bb_get_inception( array('format' => 'mysql') + $args), $args );
	echo _bb_time_function_return( $time, $args );
}

/**
 * Get the date when current installation was created
 *
 * @since 0.8
 * @uses $bbdb Database Object
 * @uses $bb_inception Result cache
 *
 * @param string|array $args Formatting options for the timestamp.
 * @return int
 */
function bb_get_inception( $args = '' ) {
	$args = _bb_parse_time_function_args( $args );

	global $bbdb, $bb_inception;
	if ( !isset($bb_inception) )
		$bb_inception = $bbdb->get_var("SELECT topic_start_time FROM $bbdb->topics ORDER BY topic_start_time LIMIT 1");

	return apply_filters( 'bb_get_inception', _bb_time_function_return( $bb_inception, $args ) );
}

/**
 * Get the average number of registrations per day
 *
 * @since 0.7.2
 *
 * @return int|float
 */
function get_registrations_per_day() {
	return get_total_users() / ceil( ( time() - bb_get_inception( 'timestamp' ) ) / 3600 / 24 );
}

/**
 * Output the average number of registrations per day
 *
 * @since 0.7.2
 */
function registrations_per_day() {
	echo apply_filters('registrations_per_day', bb_number_format_i18n(get_registrations_per_day(),3));
}

/**
 * Get the average number of posts per day
 *
 * @since 0.7.2
 *
 * @return int|float
 */
function get_posts_per_day() {
	return get_total_posts() / ceil( ( time() - bb_get_inception( 'timestamp' ) ) / 3600 / 24 );
}

/**
 * Output the average number of posts per day
 *
 * @since 0.7.2
 */
function posts_per_day() {
	echo apply_filters('posts_per_day', bb_number_format_i18n(get_posts_per_day(),3));
}

/**
 * Get the average number of topics per day
 *
 * @since 0.7.2
 *
 * @return int|float
 */
function get_topics_per_day() {
	return get_total_topics() / ceil( ( time() - bb_get_inception( 'timestamp' ) ) / 3600 / 24 );
}

/**
 * Output the average number of topics per day
 *
 * @since 0.7.2
 */
function topics_per_day() {
	echo apply_filters('topics_per_day', bb_number_format_i18n(get_topics_per_day(),3));
}
