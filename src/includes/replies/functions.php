<?php

/**
 * bbPress Reply Functions
 *
 * @package bbPress
 * @subpackage Functions
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/** Insert ********************************************************************/

/**
 * A wrapper for wp_insert_post() that also includes the necessary meta values
 * for the reply to function properly.
 *
 * @since 2.0.0 bbPress (r3349)
 *
 * @uses bbp_parse_args()
 * @uses bbp_get_reply_post_type()
 * @uses wp_insert_post()
 * @uses update_post_meta()
 *
 * @param array $reply_data Forum post data
 * @param arrap $reply_meta Forum meta data
 */
function bbp_insert_reply( $reply_data = array(), $reply_meta = array() ) {

	// Parse arguments against default values
	$reply_data = bbp_parse_args( $reply_data, array(
		'post_parent'    => 0, // topic ID
		'post_status'    => bbp_get_public_status_id(),
		'post_type'      => bbp_get_reply_post_type(),
		'post_author'    => bbp_get_current_user_id(),
		'post_password'  => '',
		'post_content'   => '',
		'post_title'     => '',
		'menu_order'     => bbp_get_topic_reply_count( $reply_data['post_parent'], true ) + 1,
		'comment_status' => 'closed'
	), 'insert_reply' );

	// Insert reply
	$reply_id = wp_insert_post( $reply_data );

	// Bail if no reply was added
	if ( empty( $reply_id ) ) {
		return false;
	}

	// Parse arguments against default values
	$reply_meta = bbp_parse_args( $reply_meta, array(
		'author_ip' => bbp_current_author_ip(),
		'forum_id'  => 0,
		'topic_id'  => 0,
		'reply_to'  => 0
	), 'insert_reply_meta' );

	// Insert reply meta
	foreach ( $reply_meta as $meta_key => $meta_value ) {
		update_post_meta( $reply_id, '_bbp_' . $meta_key, $meta_value );
	}

	// Update the reply and hierarchy
	bbp_update_reply( $reply_id, $reply_meta['topic_id'], $reply_meta['forum_id'], array(), $reply_data['post_author'], false, $reply_meta['reply_to'] );

	/**
	 * Fires after reply has been inserted via `bbp_insert_reply`.
	 *
	 * @since 2.6.0 bbPress (r6036)
	 *
	 * @param int $reply_id               The reply id.
	 * @param int $reply_meta['topic_id'] The reply topic meta.
	 * @param int $reply_meta['forum_id'] The reply forum meta.
	 */
	do_action( 'bbp_insert_reply', (int) $reply_id, (int) $reply_meta['topic_id'], (int) $reply_meta['forum_id'] );

	// Return reply_id
	return $reply_id;
}

/**
 * Update counts after a reply is inserted via `bbp_insert_reply`.
 *
 * @since 2.6.0 bbPress (r6036)
 *
 * @param int $reply_id The reply id.
 * @param int $topic_id The topic id.
 * @param int $forum_id The forum id.
 *
 * @uses bbp_get_reply_status() To get the reply status
 * @uses bbp_get_public_status_id() To get the public status id
 * @uses bbp_increase_topic_reply_count() To bump the topics reply count by 1
 * @uses bbp_increase_forum_reply_count() To bump the forums reply count by 1
 * @uses bbp_increase_topic_reply_count_hidden() To bump the topics hidden reply
 *                                               count by 1
 *
 * @return void
 */
function bbp_insert_reply_update_counts( $reply_id = 0, $topic_id = 0, $forum_id = 0 ) {

	// If the reply is public, update the forum/topic reply counts.
	if ( bbp_get_reply_status( $reply_id ) === bbp_get_public_status_id() ) {
		bbp_increase_topic_reply_count( $topic_id );
		bbp_increase_forum_reply_count( $forum_id );

	// If the reply isn't public only update the topic reply hidden count.
	} else {
		bbp_increase_topic_reply_count_hidden( $topic_id );
	}
}

/** Post Form Handlers ********************************************************/

/**
 * Handles the front end reply submission
 *
 * @since 2.0.0 bbPress (r2574)
 *
 * @param string $action The requested action to compare this function to
 * @uses bbp_add_error() To add an error message
 * @uses bbp_verify_nonce_request() To verify the nonce and check the request
 * @uses bbp_is_anonymous() To check if an anonymous post is being made
 * @uses current_user_can() To check if the current user can publish replies
 * @uses bbp_get_current_user_id() To get the current user id
 * @uses bbp_filter_anonymous_post_data() To filter anonymous data
 * @uses is_wp_error() To check if the value retrieved is a {@link WP_Error}
 * @uses remove_filter() To remove kses filters if needed
 * @uses bbp_check_for_flood() To check for flooding
 * @uses bbp_check_for_duplicate() To check for duplicates
 * @uses apply_filters() Calls 'bbp_new_reply_pre_title' with the title
 * @uses apply_filters() Calls 'bbp_new_reply_pre_content' with the content
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses wp_set_post_terms() To set the topic tags
 * @uses wp_insert_post() To insert the reply
 * @uses do_action() Calls 'bbp_new_reply' with the reply id, topic id, forum
 *                    id, anonymous data, reply author, edit (false), and
 *                    the reply to id
 * @uses bbp_get_reply_url() To get the paginated url to the reply
 * @uses bbp_redirect() To redirect to the reply url
 * @uses bbPress::errors::get_error_message() To get the {@link WP_Error} error
 *                                              message
 */
function bbp_new_reply_handler( $action = '' ) {

	// Bail if action is not bbp-new-reply
	if ( 'bbp-new-reply' !== $action ) {
		return;
	}

	// Nonce check
	if ( ! bbp_verify_nonce_request( 'bbp-new-reply' ) ) {
		bbp_add_error( 'bbp_new_reply_nonce', __( '<strong>ERROR</strong>: Are you sure you wanted to do that?', 'bbpress' ) );
		return;
	}

	// Define local variable(s)
	$topic_id = $forum_id = $reply_author = $reply_to = 0;
	$reply_title = $reply_content = $terms = '';
	$anonymous_data = array();

	/** Reply Author **********************************************************/

	// User is anonymous
	if ( bbp_is_anonymous() ) {

		// Filter anonymous data (variable is used later)
		$anonymous_data = bbp_filter_anonymous_post_data();

		// Anonymous data checks out, so set cookies, etc...
		bbp_set_current_anonymous_user_data( $anonymous_data );

	// User is logged in
	} else {

		// User cannot create replies
		if ( ! current_user_can( 'publish_replies' ) ) {
			bbp_add_error( 'bbp_reply_permission', __( '<strong>ERROR</strong>: You do not have permission to reply.', 'bbpress' ) );
		}

		// Reply author is current user
		$reply_author = bbp_get_current_user_id();
	}

	/** Topic ID **************************************************************/

	// Topic id was not passed
	if ( empty( $_POST['bbp_topic_id'] ) ) {
		bbp_add_error( 'bbp_reply_topic_id', __( '<strong>ERROR</strong>: Topic ID is missing.', 'bbpress' ) );

	// Topic id is not a number
	} elseif ( ! is_numeric( $_POST['bbp_topic_id'] ) ) {
		bbp_add_error( 'bbp_reply_topic_id', __( '<strong>ERROR</strong>: Topic ID must be a number.', 'bbpress' ) );

	// Topic id might be valid
	} else {

		// Get the topic id
		$posted_topic_id = intval( $_POST['bbp_topic_id'] );

		// Topic id is a negative number
		if ( 0 > $posted_topic_id ) {
			bbp_add_error( 'bbp_reply_topic_id', __( '<strong>ERROR</strong>: Topic ID cannot be a negative number.', 'bbpress' ) );

		// Topic does not exist
		} elseif ( ! bbp_get_topic( $posted_topic_id ) ) {
			bbp_add_error( 'bbp_reply_topic_id', __( '<strong>ERROR</strong>: Topic does not exist.', 'bbpress' ) );

		// Use the POST'ed topic id
		} else {
			$topic_id = $posted_topic_id;
		}
	}

	/** Forum ID **************************************************************/

	// Try to use the forum id of the topic
	if ( ! isset( $_POST['bbp_forum_id'] ) && ! empty( $topic_id ) ) {
		$forum_id = bbp_get_topic_forum_id( $topic_id );

	// Error check the POST'ed forum id
	} elseif ( isset( $_POST['bbp_forum_id'] ) ) {

		// Empty Forum id was passed
		if ( empty( $_POST['bbp_forum_id'] ) ) {
			bbp_add_error( 'bbp_reply_forum_id', __( '<strong>ERROR</strong>: Forum ID is missing.', 'bbpress' ) );

		// Forum id is not a number
		} elseif ( ! is_numeric( $_POST['bbp_forum_id'] ) ) {
			bbp_add_error( 'bbp_reply_forum_id', __( '<strong>ERROR</strong>: Forum ID must be a number.', 'bbpress' ) );

		// Forum id might be valid
		} else {

			// Get the forum id
			$posted_forum_id = intval( $_POST['bbp_forum_id'] );

			// Forum id is empty
			if ( 0 === $posted_forum_id ) {
				bbp_add_error( 'bbp_topic_forum_id', __( '<strong>ERROR</strong>: Forum ID is missing.', 'bbpress' ) );

			// Forum id is a negative number
			} elseif ( 0 > $posted_forum_id ) {
				bbp_add_error( 'bbp_topic_forum_id', __( '<strong>ERROR</strong>: Forum ID cannot be a negative number.', 'bbpress' ) );

			// Forum does not exist
			} elseif ( ! bbp_get_forum( $posted_forum_id ) ) {
				bbp_add_error( 'bbp_topic_forum_id', __( '<strong>ERROR</strong>: Forum does not exist.', 'bbpress' ) );

			// Use the POST'ed forum id
			} else {
				$forum_id = $posted_forum_id;
			}
		}
	}

	// Forum exists
	if ( ! empty( $forum_id ) ) {

		// Forum is a category
		if ( bbp_is_forum_category( $forum_id ) ) {
			bbp_add_error( 'bbp_new_reply_forum_category', __( '<strong>ERROR</strong>: This forum is a category. No replies can be created in this forum.', 'bbpress' ) );

		// Forum is not a category
		} else {

			// Forum is closed and user cannot access
			if ( bbp_is_forum_closed( $forum_id ) && ! current_user_can( 'edit_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_new_reply_forum_closed', __( '<strong>ERROR</strong>: This forum has been closed to new replies.', 'bbpress' ) );
			}

			// Forum is private and user cannot access
			if ( bbp_is_forum_private( $forum_id ) && ! current_user_can( 'read_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_new_reply_forum_private', __( '<strong>ERROR</strong>: This forum is private and you do not have the capability to read or create new replies in it.', 'bbpress' ) );

			// Forum is hidden and user cannot access
			} elseif ( bbp_is_forum_hidden( $forum_id ) && ! current_user_can( 'read_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_new_reply_forum_hidden', __( '<strong>ERROR</strong>: This forum is hidden and you do not have the capability to read or create new replies in it.', 'bbpress' ) );
			}
		}
	}

	/** Unfiltered HTML *******************************************************/

	// Remove kses filters from title and content for capable users and if the nonce is verified
	if ( current_user_can( 'unfiltered_html' ) && ! empty( $_POST['_bbp_unfiltered_html_reply'] ) && wp_create_nonce( 'bbp-unfiltered-html-reply_' . $topic_id ) === $_POST['_bbp_unfiltered_html_reply'] ) {
		remove_filter( 'bbp_new_reply_pre_title',   'wp_filter_kses'      );
		remove_filter( 'bbp_new_reply_pre_content', 'bbp_encode_bad',  10 );
		remove_filter( 'bbp_new_reply_pre_content', 'bbp_filter_kses', 30 );
	}

	/** Reply Title ***********************************************************/

	if ( ! empty( $_POST['bbp_reply_title'] ) ) {
		$reply_title = sanitize_text_field( $_POST['bbp_reply_title'] );
	}

	// Filter and sanitize
	$reply_title = apply_filters( 'bbp_new_reply_pre_title', $reply_title );

	/** Reply Content *********************************************************/

	if ( ! empty( $_POST['bbp_reply_content'] ) ) {
		$reply_content = $_POST['bbp_reply_content'];
	}

	// Filter and sanitize
	$reply_content = apply_filters( 'bbp_new_reply_pre_content', $reply_content );

	// No reply content
	if ( empty( $reply_content ) ) {
		bbp_add_error( 'bbp_reply_content', __( '<strong>ERROR</strong>: Your reply cannot be empty.', 'bbpress' ) );
	}

	/** Reply Flooding ********************************************************/

	if ( ! bbp_check_for_flood( $anonymous_data, $reply_author ) ) {
		bbp_add_error( 'bbp_reply_flood', __( '<strong>ERROR</strong>: Slow down; you move too fast.', 'bbpress' ) );
	}

	/** Reply Duplicate *******************************************************/

	if ( ! bbp_check_for_duplicate( array( 'post_type' => bbp_get_reply_post_type(), 'post_author' => $reply_author, 'post_content' => $reply_content, 'post_parent' => $topic_id, 'anonymous_data' => $anonymous_data ) ) ) {
		bbp_add_error( 'bbp_reply_duplicate', __( '<strong>ERROR</strong>: Duplicate reply detected; it looks as though you&#8217;ve already said that.', 'bbpress' ) );
	}

	/** Reply Blacklist *******************************************************/

	if ( ! bbp_check_for_blacklist( $anonymous_data, $reply_author, $reply_title, $reply_content ) ) {
		bbp_add_error( 'bbp_reply_blacklist', __( '<strong>ERROR</strong>: Your reply cannot be created at this time.', 'bbpress' ) );
	}

	/** Reply Status **********************************************************/

	// Maybe put into moderation
	if ( ! bbp_check_for_moderation( $anonymous_data, $reply_author, $reply_title, $reply_content ) ) {
		$reply_status = bbp_get_pending_status_id();

	// Default
	} else {
		$reply_status = bbp_get_public_status_id();
	}

	/** Reply To **************************************************************/

	// Handle Reply To of the reply; $_REQUEST for non-JS submissions
	if ( isset( $_REQUEST['bbp_reply_to'] ) ) {
		$reply_to = bbp_validate_reply_to( $_REQUEST['bbp_reply_to'] );
	}

	/** Topic Closed **********************************************************/

	// If topic is closed, moderators can still reply
	if ( bbp_is_topic_closed( $topic_id ) && ! current_user_can( 'moderate', $topic_id ) ) {
		bbp_add_error( 'bbp_reply_topic_closed', __( '<strong>ERROR</strong>: Topic is closed.', 'bbpress' ) );
	}

	/** Topic Tags ************************************************************/

	// Either replace terms
	if ( bbp_allow_topic_tags() && current_user_can( 'assign_topic_tags' ) && ! empty( $_POST['bbp_topic_tags'] ) ) {
		$terms = sanitize_text_field( $_POST['bbp_topic_tags'] );

	// ...or remove them.
	} elseif ( isset( $_POST['bbp_topic_tags'] ) ) {
		$terms = '';

	// Existing terms
	} else {
		$terms = bbp_get_topic_tag_names( $topic_id );
	}

	/** Additional Actions (Before Save) **************************************/

	do_action( 'bbp_new_reply_pre_extras', $topic_id, $forum_id );

	// Bail if errors
	if ( bbp_has_errors() ) {
		return;
	}

	/** No Errors *************************************************************/

	// Add the content of the form to $reply_data as an array
	// Just in time manipulation of reply data before being created
	$reply_data = apply_filters( 'bbp_new_reply_pre_insert', array(
		'post_author'    => $reply_author,
		'post_title'     => $reply_title,
		'post_content'   => $reply_content,
		'post_status'    => $reply_status,
		'post_parent'    => $topic_id,
		'post_type'      => bbp_get_reply_post_type(),
		'comment_status' => 'closed',
		'menu_order'     => bbp_get_topic_reply_count( $topic_id, true ) + 1
	) );

	// Insert reply
	$reply_id = wp_insert_post( $reply_data );

	/** No Errors *************************************************************/

	// Check for missing reply_id or error
	if ( ! empty( $reply_id ) && ! is_wp_error( $reply_id ) ) {

		/** Topic Tags ********************************************************/

		// Just in time manipulation of reply terms before being edited
		$terms = apply_filters( 'bbp_new_reply_pre_set_terms', $terms, $topic_id, $reply_id );

		// Insert terms
		$terms = wp_set_post_terms( $topic_id, $terms, bbp_get_topic_tag_tax_id(), false );

		// Term error
		if ( is_wp_error( $terms ) ) {
			bbp_add_error( 'bbp_reply_tags', __( '<strong>ERROR</strong>: There was a problem adding the tags to the topic.', 'bbpress' ) );
		}

		/** Trash Check *******************************************************/

		// If this reply starts as trash, add it to pre_trashed_replies
		// for the topic, so it is properly restored.
		if ( bbp_is_topic_trash( $topic_id ) || ( $reply_data['post_status'] === bbp_get_trash_status_id() ) ) {

			// Trash the reply
			wp_trash_post( $reply_id );

			// Only add to pre-trashed array if topic is trashed
			if ( bbp_is_topic_trash( $topic_id ) ) {

				// Get pre_trashed_replies for topic
				$pre_trashed_replies = (array) get_post_meta( $topic_id, '_bbp_pre_trashed_replies', true );

				// Add this reply to the end of the existing replies
				$pre_trashed_replies[] = $reply_id;

				// Update the pre_trashed_reply post meta
				update_post_meta( $topic_id, '_bbp_pre_trashed_replies', $pre_trashed_replies );
			}

		/** Spam Check ********************************************************/

		// If reply or topic are spam, officially spam this reply
		} elseif ( bbp_is_topic_spam( $topic_id ) || ( $reply_data['post_status'] === bbp_get_spam_status_id() ) ) {
			add_post_meta( $reply_id, '_bbp_spam_meta_status', bbp_get_public_status_id() );

			// Only add to pre-spammed array if topic is spam
			if ( bbp_is_topic_spam( $topic_id ) ) {

				// Get pre_spammed_replies for topic
				$pre_spammed_replies = (array) get_post_meta( $topic_id, '_bbp_pre_spammed_replies', true );

				// Add this reply to the end of the existing replies
				$pre_spammed_replies[] = $reply_id;

				// Update the pre_spammed_replies post meta
				update_post_meta( $topic_id, '_bbp_pre_spammed_replies', $pre_spammed_replies );
			}
		}

		/** Update counts, etc... *********************************************/

		do_action( 'bbp_new_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author, false, $reply_to );

		/** Additional Actions (After Save) ***********************************/

		do_action( 'bbp_new_reply_post_extras', $reply_id );

		/** Redirect **********************************************************/

		// Redirect to
		$redirect_to = bbp_get_redirect_to();

		// Get the reply URL
		$reply_url = bbp_get_reply_url( $reply_id, $redirect_to );

		// Allow to be filtered
		$reply_url = apply_filters( 'bbp_new_reply_redirect_to', $reply_url, $redirect_to, $reply_id );

		/** Successful Save ***************************************************/

		// Redirect back to new reply
		bbp_redirect( $reply_url );

	/** Errors ****************************************************************/

	} else {
		$append_error = ( is_wp_error( $reply_id ) && $reply_id->get_error_message() ) ? $reply_id->get_error_message() . ' ' : '';
		bbp_add_error( 'bbp_reply_error', __( '<strong>ERROR</strong>: The following problem(s) have been found with your reply:' . $append_error . 'Please try again.', 'bbpress' ) );
	}
}

/**
 * Handles the front end edit reply submission
 *
 * @param string $action The requested action to compare this function to
 * @uses bbp_add_error() To add an error message
 * @uses bbp_get_reply() To get the reply
 * @uses bbp_verify_nonce_request() To verify the nonce and check the request
 * @uses bbp_is_reply_anonymous() To check if the reply was by an anonymous user
 * @uses current_user_can() To check if the current user can edit that reply
 * @uses bbp_filter_anonymous_post_data() To filter anonymous data
 * @uses is_wp_error() To check if the value retrieved is a {@link WP_Error}
 * @uses remove_filter() To remove kses filters if needed
 * @uses apply_filters() Calls 'bbp_edit_reply_pre_title' with the title and id
 * @uses apply_filters() Calls 'bbp_edit_reply_pre_content' with the content id
 * @uses wp_set_post_terms() To set the topic tags
 * @uses bbp_has_errors() To get the {@link WP_Error} errors
 * @uses wp_save_post_revision() To save a reply revision
 * @uses bbp_update_reply_revision_log() To update the reply revision log
 * @uses wp_update_post() To update the reply
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_topic_forum_id() To get the topic forum id
 * @uses bbp_get_reply_to() To get the reply to id
 * @uses do_action() Calls 'bbp_edit_reply' with the reply id, topic id, forum
 *                    id, anonymous data, reply author, bool true (for edit),
 *                    and the reply to id
 * @uses bbp_get_reply_url() To get the paginated url to the reply
 * @uses bbp_redirect() To redirect to the reply url
 * @uses bbPress::errors::get_error_message() To get the {@link WP_Error} error
 *                                             message
 */
function bbp_edit_reply_handler( $action = '' ) {

	// Bail if action is not bbp-edit-reply
	if ( 'bbp-edit-reply' !== $action ) {
		return;
	}

	// Define local variable(s)
	$revisions_removed = false;
	$reply = $reply_id = $reply_to = $reply_author = $topic_id = $forum_id = 0;
	$reply_title = $reply_content = $reply_edit_reason = $terms = '';
	$anonymous_data = array();

	/** Reply *****************************************************************/

	// Reply id was not passed
	if ( empty( $_POST['bbp_reply_id'] ) ) {
		bbp_add_error( 'bbp_edit_reply_id', __( '<strong>ERROR</strong>: Reply ID not found.', 'bbpress' ) );
		return;

	// Reply id was passed
	} elseif ( is_numeric( $_POST['bbp_reply_id'] ) ) {
		$reply_id = (int) $_POST['bbp_reply_id'];
		$reply    = bbp_get_reply( $reply_id );
	}

	// Nonce check
	if ( ! bbp_verify_nonce_request( 'bbp-edit-reply_' . $reply_id ) ) {
		bbp_add_error( 'bbp_edit_reply_nonce', __( '<strong>ERROR</strong>: Are you sure you wanted to do that?', 'bbpress' ) );
		return;
	}

	// Reply does not exist
	if ( empty( $reply ) ) {
		bbp_add_error( 'bbp_edit_reply_not_found', __( '<strong>ERROR</strong>: The reply you want to edit was not found.', 'bbpress' ) );
		return;

	// Reply exists
	} else {

		// Check users ability to create new reply
		if ( ! bbp_is_reply_anonymous( $reply_id ) ) {

			// User cannot edit this reply
			if ( ! current_user_can( 'edit_reply', $reply_id ) ) {
				bbp_add_error( 'bbp_edit_reply_permission', __( '<strong>ERROR</strong>: You do not have permission to edit that reply.', 'bbpress' ) );
				return;
			}

			// Set reply author
			$reply_author = bbp_get_reply_author_id( $reply_id );

		// It is an anonymous post
		} else {

			// Filter anonymous data
			$anonymous_data = bbp_filter_anonymous_post_data();
		}
	}

	// Remove kses filters from title and content for capable users and if the nonce is verified
	if ( current_user_can( 'unfiltered_html' ) && ! empty( $_POST['_bbp_unfiltered_html_reply'] ) && wp_create_nonce( 'bbp-unfiltered-html-reply_' . $reply_id ) === $_POST['_bbp_unfiltered_html_reply'] ) {
		remove_filter( 'bbp_edit_reply_pre_title',   'wp_filter_kses'      );
		remove_filter( 'bbp_edit_reply_pre_content', 'bbp_encode_bad',  10 );
		remove_filter( 'bbp_edit_reply_pre_content', 'bbp_filter_kses', 30 );
	}

	/** Reply Topic ***********************************************************/

	$topic_id = bbp_get_reply_topic_id( $reply_id );

	/** Topic Forum ***********************************************************/

	$forum_id = bbp_get_topic_forum_id( $topic_id );

	// Forum exists
	if ( ! empty( $forum_id ) && ( $forum_id !== bbp_get_reply_forum_id( $reply_id ) ) ) {

		// Forum is a category
		if ( bbp_is_forum_category( $forum_id ) ) {
			bbp_add_error( 'bbp_edit_reply_forum_category', __( '<strong>ERROR</strong>: This forum is a category. No replies can be created in this forum.', 'bbpress' ) );

		// Forum is not a category
		} else {

			// Forum is closed and user cannot access
			if ( bbp_is_forum_closed( $forum_id ) && ! current_user_can( 'edit_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_edit_reply_forum_closed', __( '<strong>ERROR</strong>: This forum has been closed to new replies.', 'bbpress' ) );
			}

			// Forum is private and user cannot access
			if ( bbp_is_forum_private( $forum_id ) && ! current_user_can( 'read_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_edit_reply_forum_private', __( '<strong>ERROR</strong>: This forum is private and you do not have the capability to read or create new replies in it.', 'bbpress' ) );

			// Forum is hidden and user cannot access
			} elseif ( bbp_is_forum_hidden( $forum_id ) && ! current_user_can( 'read_forum', $forum_id ) ) {
				bbp_add_error( 'bbp_edit_reply_forum_hidden', __( '<strong>ERROR</strong>: This forum is hidden and you do not have the capability to read or create new replies in it.', 'bbpress' ) );
			}
		}
	}

	/** Reply Title ***********************************************************/

	if ( ! empty( $_POST['bbp_reply_title'] ) ) {
		$reply_title = sanitize_text_field( $_POST['bbp_reply_title'] );
	}

	// Filter and sanitize
	$reply_title = apply_filters( 'bbp_edit_reply_pre_title', $reply_title, $reply_id );

	/** Reply Content *********************************************************/

	if ( ! empty( $_POST['bbp_reply_content'] ) ) {
		$reply_content = $_POST['bbp_reply_content'];
	}

	// Filter and sanitize
	$reply_content = apply_filters( 'bbp_edit_reply_pre_content', $reply_content, $reply_id );

	// No reply content
	if ( empty( $reply_content ) ) {
		bbp_add_error( 'bbp_edit_reply_content', __( '<strong>ERROR</strong>: Your reply cannot be empty.', 'bbpress' ) );
	}

	/** Reply Blacklist *******************************************************/

	if ( ! bbp_check_for_blacklist( $anonymous_data, $reply_author, $reply_title, $reply_content ) ) {
		bbp_add_error( 'bbp_reply_blacklist', __( '<strong>ERROR</strong>: Your reply cannot be edited at this time.', 'bbpress' ) );
	}

	/** Reply Status **********************************************************/

	// Maybe put into moderation
	if ( ! bbp_check_for_moderation( $anonymous_data, $reply_author, $reply_title, $reply_content ) ) {

		// Set post status to pending if public
		if ( bbp_get_public_status_id() === $reply->post_status ) {
			$reply_status = bbp_get_pending_status_id();
		}

	// Use existing post_status
	} else {
		$reply_status = $reply->post_status;
	}

	/** Reply To **************************************************************/

	// Handle Reply To of the reply; $_REQUEST for non-JS submissions
	if ( isset( $_REQUEST['bbp_reply_to'] ) ) {
		$reply_to = bbp_validate_reply_to( $_REQUEST['bbp_reply_to'], $reply_id );
	}

	/** Topic Tags ************************************************************/

	// Either replace terms
	if ( bbp_allow_topic_tags() && current_user_can( 'assign_topic_tags' ) && ! empty( $_POST['bbp_topic_tags'] ) ) {
		$terms = sanitize_text_field( $_POST['bbp_topic_tags'] );

	// ...or remove them.
	} elseif ( isset( $_POST['bbp_topic_tags'] ) ) {
		$terms = '';

	// Existing terms
	} else {
		$terms = bbp_get_topic_tag_names( $topic_id );
	}

	/** Additional Actions (Before Save) **************************************/

	do_action( 'bbp_edit_reply_pre_extras', $reply_id );

	// Bail if errors
	if ( bbp_has_errors() ) {
		return;
	}

	/** No Errors *************************************************************/

	// Add the content of the form to $reply_data as an array
	// Just in time manipulation of reply data before being edited
	$reply_data = apply_filters( 'bbp_edit_reply_pre_insert', array(
		'ID'           => $reply_id,
		'post_title'   => $reply_title,
		'post_content' => $reply_content,
		'post_status'  => $reply_status,
		'post_parent'  => $topic_id,
		'post_author'  => $reply_author,
		'post_type'    => bbp_get_reply_post_type()
	) );

	// Toggle revisions to avoid duplicates
	if ( post_type_supports( bbp_get_reply_post_type(), 'revisions' ) ) {
		$revisions_removed = true;
		remove_post_type_support( bbp_get_reply_post_type(), 'revisions' );
	}

	// Insert reply
	$reply_id = wp_update_post( $reply_data );

	// Toggle revisions back on
	if ( true === $revisions_removed ) {
		$revisions_removed = false;
		add_post_type_support( bbp_get_reply_post_type(), 'revisions' );
	}

	/** Topic Tags ************************************************************/

	// Just in time manipulation of reply terms before being edited
	$terms = apply_filters( 'bbp_edit_reply_pre_set_terms', $terms, $topic_id, $reply_id );

	// Insert terms
	$terms = wp_set_post_terms( $topic_id, $terms, bbp_get_topic_tag_tax_id(), false );

	// Term error
	if ( is_wp_error( $terms ) ) {
		bbp_add_error( 'bbp_reply_tags', __( '<strong>ERROR</strong>: There was a problem adding the tags to the topic.', 'bbpress' ) );
	}

	/** No Errors *************************************************************/

	if ( ! empty( $reply_id ) && ! is_wp_error( $reply_id ) ) {

		// Update counts, etc...
		do_action( 'bbp_edit_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author , true, $reply_to );

		/** Revisions *********************************************************/

		// Update locks
		update_post_meta( $reply_id, '_edit_last', bbp_get_current_user_id() );
		delete_post_meta( $reply_id, '_edit_lock' );

		// Revision Reason
		if ( ! empty( $_POST['bbp_reply_edit_reason'] ) ) {
			$reply_edit_reason = sanitize_text_field( $_POST['bbp_reply_edit_reason'] );
		}

		// Update revision log
		if ( ! empty( $_POST['bbp_log_reply_edit'] ) && ( "1" === $_POST['bbp_log_reply_edit'] ) ) {
			$revision_id = wp_save_post_revision( $reply_id );
			if ( ! empty( $revision_id ) ) {
				bbp_update_reply_revision_log( array(
					'reply_id'    => $reply_id,
					'revision_id' => $revision_id,
					'author_id'   => bbp_get_current_user_id(),
					'reason'      => $reply_edit_reason
				) );
			}
		}

		/** Additional Actions (After Save) ***********************************/

		do_action( 'bbp_edit_reply_post_extras', $reply_id );

		/** Redirect **********************************************************/

		// Redirect to
		$redirect_to = bbp_get_redirect_to();

		// Get the reply URL
		$reply_url = bbp_get_reply_url( $reply_id, $redirect_to );

		// Allow to be filtered
		$reply_url = apply_filters( 'bbp_edit_reply_redirect_to', $reply_url, $redirect_to );

		/** Successful Edit ***************************************************/

		// Redirect back to new reply
		bbp_redirect( $reply_url );

	/** Errors ****************************************************************/

	} else {
		$append_error = ( is_wp_error( $reply_id ) && $reply_id->get_error_message() ) ? $reply_id->get_error_message() . ' ' : '';
		bbp_add_error( 'bbp_reply_error', __( '<strong>ERROR</strong>: The following problem(s) have been found with your reply:' . $append_error . 'Please try again.', 'bbpress' ) );
	}
}

/**
 * Handle all the extra meta stuff from posting a new reply or editing a reply
 *
 * @param int $reply_id Optional. Reply id
 * @param int $topic_id Optional. Topic id
 * @param int $forum_id Optional. Forum id
 * @param array $anonymous_data Optional - if it's an anonymous post. Do not
 *                              supply if supplying $author_id. Should be
 *                              sanitized (see {@link bbp_filter_anonymous_post_data()}
 * @param int $author_id Author id
 * @param bool $is_edit Optional. Is the post being edited? Defaults to false.
 * @param int $reply_to Optional. Reply to id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_topic_id() To get the topic id
 * @uses bbp_get_forum_id() To get the forum id
 * @uses bbp_get_current_user_id() To get the current user id
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_topic_forum_id() To get the topic forum id
 * @uses update_post_meta() To update the reply metas
 * @uses set_transient() To update the flood check transient for the ip
 * @uses bbp_update_user_last_posted() To update the users last posted time
 * @uses bbp_is_subscriptions_active() To check if the subscriptions feature is
 *                                      activated or not
 * @uses bbp_is_user_subscribed() To check if the user is subscribed
 * @uses bbp_remove_user_subscription() To remove the user's subscription
 * @uses bbp_add_user_subscription() To add the user's subscription
 * @uses bbp_update_reply_forum_id() To update the reply forum id
 * @uses bbp_update_reply_topic_id() To update the reply topic id
 * @uses bbp_update_reply_to() To update the reply to id
 * @uses bbp_update_reply_walker() To update the reply's ancestors' counts
 */
function bbp_update_reply( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = array(), $author_id = 0, $is_edit = false, $reply_to = 0 ) {

	// Validate the ID's passed from 'bbp_new_reply' action
	$reply_id = bbp_get_reply_id( $reply_id );
	$topic_id = bbp_get_topic_id( $topic_id );
	$forum_id = bbp_get_forum_id( $forum_id );
	$reply_to = bbp_validate_reply_to( $reply_to, $reply_id );

	// Bail if there is no reply
	if ( empty( $reply_id ) ) {
		return;
	}

	// Check author_id
	if ( empty( $author_id ) ) {
		$author_id = bbp_get_current_user_id();
	}

	// Check topic_id
	if ( empty( $topic_id ) ) {
		$topic_id = bbp_get_reply_topic_id( $reply_id );
	}

	// Check forum_id
	if ( ! empty( $topic_id ) && empty( $forum_id ) ) {
		$forum_id = bbp_get_topic_forum_id( $topic_id );
	}

	// If anonymous post, store name, email, website and ip in post_meta.
	if ( ! empty( $anonymous_data ) ) {

		// Update anonymous meta data (not cookies)
		bbp_update_anonymous_post_author( $reply_id, $anonymous_data, 'reply' );

		// Set transient for throttle check (only on new, not edit)
		if ( empty( $is_edit ) ) {
			set_transient( '_bbp_' . bbp_current_author_ip() . '_last_posted', time() );
		}

	} else {
		if ( empty( $is_edit ) && ! current_user_can( 'throttle' ) ) {
			bbp_update_user_last_posted( $author_id );
		}
	}

	// Handle Subscription Checkbox
	if ( bbp_is_subscriptions_active() && ! empty( $author_id ) && ! empty( $topic_id ) ) {
		$subscribed = bbp_is_user_subscribed( $author_id, $topic_id );
		$subscheck  = ( ! empty( $_POST['bbp_topic_subscription'] ) && ( 'bbp_subscribe' === $_POST['bbp_topic_subscription'] ) ) ? true : false;

		// Subscribed and unsubscribing
		if ( true === $subscribed && false === $subscheck ) {
			bbp_remove_user_subscription( $author_id, $topic_id );

		// Subscribing
		} elseif ( false === $subscribed && true === $subscheck ) {
			bbp_add_user_subscription( $author_id, $topic_id );
		}
	}

	// Reply meta relating to reply position in tree
	bbp_update_reply_forum_id( $reply_id, $forum_id );
	bbp_update_reply_topic_id( $reply_id, $topic_id );
	bbp_update_reply_to      ( $reply_id, $reply_to );

	// Update associated topic values if this is a new reply
	if ( empty( $is_edit ) ) {

		// Update poster IP if not editing
		update_post_meta( $reply_id, '_bbp_author_ip', bbp_current_author_ip(), false );

		// Last active time
		$last_active_time = get_post_field( 'post_date', $reply_id );

		// Walk up ancestors and do the dirty work
		bbp_update_reply_walker( $reply_id, $last_active_time, $forum_id, $topic_id, false );
	}
}

/**
 * Walk up the ancestor tree from the current reply, and update all the counts
 *
 * @since 2.0.0 bbPress (r2884)
 *
 * @param int $reply_id Optional. Reply id
 * @param string $last_active_time Optional. Last active time
 * @param int $forum_id Optional. Forum id
 * @param int $topic_id Optional. Topic id
 * @param bool $refresh If set to true, unsets all the previous parameters.
 *                       Defaults to true
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_reply_topic_id() To get the reply topic id
 * @uses bbp_get_reply_forum_id() To get the reply forum id
 * @uses get_post_ancestors() To get the ancestors of the reply
 * @uses bbp_is_reply() To check if the ancestor is a reply
 * @uses bbp_is_topic() To check if the ancestor is a topic
 * @uses bbp_update_topic_last_reply_id() To update the topic last reply id
 * @uses bbp_update_topic_last_active_id() To update the topic last active id
 * @uses bbp_get_topic_last_active_id() To get the topic last active id
 * @uses get_post_field() To get the post date of the last active id
 * @uses bbp_update_topic_last_active_time() To update the last active topic meta
 * @uses bbp_update_topic_voice_count() To update the topic voice count
 * @uses bbp_update_topic_reply_count() To update the topic reply count
 * @uses bbp_update_topic_reply_count_hidden() To update the topic hidden reply
 *                                              count
 * @uses bbp_is_forum() To check if the ancestor is a forum
 * @uses bbp_update_forum_last_topic_id() To update the last topic id forum meta
 * @uses bbp_update_forum_last_reply_id() To update the last reply id forum meta
 * @uses bbp_update_forum_last_active_id() To update the forum last active id
 * @uses bbp_get_forum_last_active_id() To get the forum last active id
 * @uses bbp_update_forum_last_active_time() To update the forum last active time
 * @uses bbp_update_forum_reply_count() To update the forum reply count
 */
function bbp_update_reply_walker( $reply_id, $last_active_time = '', $forum_id = 0, $topic_id = 0, $refresh = true ) {

	// Verify the reply ID
	$reply_id = bbp_get_reply_id( $reply_id );

	// Reply was passed
	if ( ! empty( $reply_id ) ) {

		// Get the topic ID if none was passed
		if ( empty( $topic_id ) ) {
			$topic_id = bbp_get_reply_topic_id( $reply_id );

			// Make every effort to get topic id
			// https://bbpress.trac.wordpress.org/ticket/2529
			if ( empty( $topic_id ) && ( current_filter() === 'bbp_deleted_reply' ) ) {
				$topic_id = get_post_field( 'post_parent', $reply_id );
			}
		}

		// Get the forum ID if none was passed
		if ( empty( $forum_id ) ) {
			$forum_id = bbp_get_reply_forum_id( $reply_id );
		}
	}

	// Set the active_id based on topic_id/reply_id
	$active_id = empty( $reply_id ) ? $topic_id : $reply_id;

	// Setup ancestors array to walk up
	$ancestors = array_values( array_unique( array_merge( array( $topic_id, $forum_id ), (array) get_post_ancestors( $topic_id ) ) ) );

	// If we want a full refresh, unset any of the possibly passed variables
	if ( true === $refresh ) {
		$forum_id = $topic_id = $reply_id = $active_id = $last_active_time = 0;
	}

	// Walk up ancestors
	if ( ! empty( $ancestors ) ) {
		foreach ( $ancestors as $ancestor ) {

			// Reply meta relating to most recent reply
			if ( bbp_is_reply( $ancestor ) ) {
				// @todo - hierarchical replies

			// Topic meta relating to most recent reply
			} elseif ( bbp_is_topic( $ancestor ) ) {

				// Last reply and active ID's
				bbp_update_topic_last_reply_id ( $ancestor, $reply_id  );
				bbp_update_topic_last_active_id( $ancestor, $active_id );

				// Get the last active time if none was passed
				$topic_last_active_time = $last_active_time;
				if ( empty( $last_active_time ) ) {
					$topic_last_active_time = get_post_field( 'post_date', bbp_get_topic_last_active_id( $ancestor ) );
				}

				// Update the topic last active time regardless of reply status.
				// See https://bbpress.trac.wordpress.org/ticket/2838
				bbp_update_topic_last_active_time( $ancestor, $topic_last_active_time );

				// Only update reply count if we're deleting a reply, or in the dashboard.
				if ( in_array( current_filter(), array( 'bbp_deleted_reply', 'save_post' ), true ) ) {
					bbp_update_topic_reply_count(        $ancestor );
					bbp_update_topic_reply_count_hidden( $ancestor );
					bbp_update_topic_voice_count(        $ancestor );
				}

			// Forum meta relating to most recent topic
			} elseif ( bbp_is_forum( $ancestor ) ) {

				// Last topic and reply ID's
				bbp_update_forum_last_topic_id( $ancestor, $topic_id );
				bbp_update_forum_last_reply_id( $ancestor, $reply_id );

				// Last Active
				bbp_update_forum_last_active_id( $ancestor, $active_id );

				// Get the last active time if none was passed
				$forum_last_active_time = $last_active_time;
				if ( empty( $last_active_time ) ) {
					$forum_last_active_time = get_post_field( 'post_date', bbp_get_forum_last_active_id( $ancestor ) );
				}

				// Only update if reply is published
				if ( bbp_is_reply_published( $reply_id ) ) {
					bbp_update_forum_last_active_time( $ancestor, $forum_last_active_time );
				}

				// Counts
				// Only update reply count if we're deleting a reply, or in the dashboard.
				if ( in_array( current_filter(), array( 'bbp_deleted_reply', 'save_post' ), true ) ) {
					bbp_update_forum_reply_count( $ancestor );
				}
			}
		}
	}
}

/** Reply Updaters ************************************************************/

/**
 * Update the reply with its forum id it is in
 *
 * @since 2.0.0 bbPress (r2855)
 *
 * @param int $reply_id Optional. Reply id to update
 * @param int $forum_id Optional. Forum id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_forum_id() To get the forum id
 * @uses get_post_ancestors() To get the reply's forum
 * @uses get_post_field() To get the post type of the post
 * @uses update_post_meta() To update the reply forum id meta
 * @uses apply_filters() Calls 'bbp_update_reply_forum_id' with the forum id
 *                        and reply id
 * @return bool Reply's forum id
 */
function bbp_update_reply_forum_id( $reply_id = 0, $forum_id = 0 ) {

	// Validation
	$reply_id = bbp_get_reply_id( $reply_id );
	$forum_id = bbp_get_forum_id( $forum_id );

	// If no forum_id was passed, walk up ancestors and look for forum type
	if ( empty( $forum_id ) ) {

		// Get ancestors
		$ancestors = get_post_ancestors( $reply_id );

		// Loop through ancestors
		if ( ! empty( $ancestors ) ) {
			foreach ( $ancestors as $ancestor ) {

				// Get first parent that is a forum
				if ( get_post_field( 'post_type', $ancestor ) === bbp_get_forum_post_type() ) {
					$forum_id = $ancestor;

					// Found a forum, so exit the loop and continue
					continue;
				}
			}
		}
	}

	// Update the forum ID
	$forum_id = bbp_update_forum_id( $reply_id, $forum_id );

	// Filter & return
	return (int) apply_filters( 'bbp_update_reply_forum_id', $forum_id, $reply_id );
}

/**
 * Update the reply with its topic id it is in
 *
 * @since 2.0.0 bbPress (r2855)
 *
 * @param int $reply_id Optional. Reply id to update
 * @param int $topic_id Optional. Topic id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_get_topic_id() To get the topic id
 * @uses get_post_ancestors() To get the reply's topic
 * @uses get_post_field() To get the post type of the post
 * @uses update_post_meta() To update the reply topic id meta
 * @uses apply_filters() Calls 'bbp_update_reply_topic_id' with the topic id
 *                        and reply id
 * @return bool Reply's topic id
 */
function bbp_update_reply_topic_id( $reply_id = 0, $topic_id = 0 ) {

	// Validation
	$reply_id = bbp_get_reply_id( $reply_id );
	$topic_id = bbp_get_topic_id( $topic_id );

	// If no topic_id was passed, walk up ancestors and look for topic type
	if ( empty( $topic_id ) ) {

		// Get ancestors
		$ancestors = (array) get_post_ancestors( $reply_id );

		// Loop through ancestors
		if ( ! empty( $ancestors ) ) {
			foreach ( $ancestors as $ancestor ) {

				// Get first parent that is a topic
				if ( get_post_field( 'post_type', $ancestor ) === bbp_get_topic_post_type() ) {
					$topic_id = $ancestor;

					// Found a topic, so exit the loop and continue
					continue;
				}
			}
		}
	}

	// Update the topic ID
	$topic_id = bbp_update_topic_id( $reply_id, $topic_id );

	// Filter & return
	return (int) apply_filters( 'bbp_update_reply_topic_id', $topic_id, $reply_id );
}

/*
 * Update the reply's meta data with its reply to id
 *
 * @since 2.4.0 bbPress (r4944)
 *
 * @param int $reply_id Reply id to update
 * @param int $reply_to Optional. Reply to id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses update_post_meta() To update the reply to meta
 * @uses apply_filters() Calls 'bbp_update_reply_to' with the reply id and
 *                        and reply to id
 * @return bool Reply's parent reply id
 */
function bbp_update_reply_to( $reply_id = 0, $reply_to = 0 ) {

	// Validation
	$reply_id = bbp_get_reply_id( $reply_id );
	$reply_to = bbp_validate_reply_to( $reply_to, $reply_id );

	// Update or delete the `reply_to` postmeta
	if ( ! empty( $reply_id ) ) {

		// Update the reply to
		if ( ! empty( $reply_to ) ) {
			$reply_to = bbp_update_reply_to_id( $reply_id, $reply_to );

		// Delete the reply to
		} else {
			delete_post_meta( $reply_id, '_bbp_reply_to' );
		}
	}

	// Filter & return
	return (int) apply_filters( 'bbp_update_reply_to', $reply_to, $reply_id );
}

/**
 * Get all ancestors to a reply
 *
 * Because settings can be changed, this function does not care if hierarchical
 * replies are active or to what depth.
 *
 * @since 2.6.0 bbPress (r5390)
 *
 * @param int $reply_id
 * @return array
 */
function bbp_get_reply_ancestors( $reply_id = 0 ) {

	// Validation
	$reply_id  = bbp_get_reply_id( $reply_id );
	$ancestors = array();

	// Reply id is valid
	if ( ! empty( $reply_id ) ) {

		// Try to get reply parent
		$reply_to = bbp_get_reply_to( $reply_id );

		// Reply has a hierarchical parent
		if ( ! empty( $reply_to ) ) {

			// Setup the current ID and current post as an ancestor
			$id        = $reply_to;
			$ancestors = array( $reply_to );

			// Get parent reply
			while ( $ancestor = bbp_get_reply( $id ) ) {

				// Does parent have a parent?
				$grampy_id = bbp_get_reply_to( $ancestor->ID );

				// Loop detection: If the ancestor has been seen before, break.
				if ( empty( $ancestor->post_parent ) || ( $grampy_id === $reply_id ) || in_array( $grampy_id, $ancestors, true ) ) {
					break;
				}

				$id = $ancestors[] = $grampy_id;
			}
		}
	}

	// Filter & return
	return (array) apply_filters( 'bbp_get_reply_ancestors', $ancestors, $reply_id );
}

/**
 * Update the revision log of the reply
 *
 * @since 2.0.0 bbPress (r2782)
 *
 * @param array $args Supports these args:
 *  - reply_id: reply id
 *  - author_id: Author id
 *  - reason: Reason for editing
 *  - revision_id: Revision id
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_format_revision_reason() To format the reason
 * @uses bbp_get_reply_raw_revision_log() To get the raw reply revision log
 * @uses update_post_meta() To update the reply revision log meta
 * @return mixed False on failure, true on success
 */
function bbp_update_reply_revision_log( $args = array() ) {

	// Parse arguments against default values
	$r = bbp_parse_args( $args, array(
		'reason'      => '',
		'reply_id'    => 0,
		'author_id'   => 0,
		'revision_id' => 0
	), 'update_reply_revision_log' );

	// Populate the variables
	$r['reason']      = bbp_format_revision_reason( $r['reason'] );
	$r['reply_id']    = bbp_get_reply_id( $r['reply_id'] );
	$r['author_id']   = bbp_get_user_id ( $r['author_id'], false, true );
	$r['revision_id'] = (int) $r['revision_id'];

	// Get the logs and append the new one to those
	$revision_log                      = bbp_get_reply_raw_revision_log( $r['reply_id'] );
	$revision_log[ $r['revision_id'] ] = array( 'author' => $r['author_id'], 'reason' => $r['reason'] );

	// Finally, update
	update_post_meta( $r['reply_id'], '_bbp_revision_log', $revision_log );

	// Filter & return
	return apply_filters( 'bbp_update_reply_revision_log', $revision_log, $r['reply_id'] );
}

/**
 * Move reply handler
 *
 * Handles the front end move reply submission
 *
 * @since 2.3.0 bbPress (r4521)
 *
 * @param string $action The requested action to compare this function to
 * @uses bbp_add_error() To add an error message
 * @uses bbp_get_reply() To get the reply
 * @uses bbp_get_topic() To get the topics
 * @uses bbp_verify_nonce_request() To verify the nonce and check the request
 * @uses current_user_can() To check if the current user can edit the reply and topics
 * @uses bbp_get_topic_post_type() To get the topic post type
 * @uses is_wp_error() To check if the value retrieved is a {@link WP_Error}
 * @uses do_action() Calls 'bbp_pre_move_reply' with the from reply id, source
 *                    and destination topic ids
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses wp_update_post() To update the replies
 * @uses bbp_update_reply_topic_id() To update the reply topic id
 * @uses bbp_get_topic_forum_id() To get the topic forum id
 * @uses bbp_update_reply_forum_id() To update the reply forum id
 * @uses do_action() Calls 'bbp_split_topic_reply' with the reply id and
 *                    destination topic id
 * @uses bbp_update_topic_last_reply_id() To update the topic last reply id
 * @uses bbp_update_topic_last_active_time() To update the topic last active meta
 * @uses do_action() Calls 'bbp_post_split_topic' with the destination and
 *                    source topic ids and source topic's forum id
 * @uses bbp_get_topic_permalink() To get the topic permalink
 * @uses bbp_redirect() To redirect to the topic link
 */
function bbp_move_reply_handler( $action = '' ) {

	// Bail if action is not 'bbp-move-reply'
	if ( 'bbp-move-reply' !== $action ) {
		return;
	}

	// Prevent debug notices
	$move_reply_id = $destination_topic_id = 0;
	$destination_topic_title = '';
	$destination_topic = $move_reply = $source_topic = '';

	/** Move Reply ***********************************************************/

	if ( empty( $_POST['bbp_reply_id'] ) ) {
		bbp_add_error( 'bbp_move_reply_reply_id', __( '<strong>ERROR</strong>: A reply ID is required', 'bbpress' ) );
	} else {
		$move_reply_id = (int) $_POST['bbp_reply_id'];
	}

	$move_reply = bbp_get_reply( $move_reply_id );

	// Reply exists
	if ( empty( $move_reply ) ) {
		bbp_add_error( 'bbp_mover_reply_r_not_found', __( '<strong>ERROR</strong>: The reply you want to move was not found.', 'bbpress' ) );
	}

	/** Topic to Move From ***************************************************/

	// Get the reply's current topic
	$source_topic = bbp_get_topic( $move_reply->post_parent );

	// No topic
	if ( empty( $source_topic ) ) {
		bbp_add_error( 'bbp_move_reply_source_not_found', __( '<strong>ERROR</strong>: The topic you want to move from was not found.', 'bbpress' ) );
	}

	// Nonce check failed
	if ( ! bbp_verify_nonce_request( 'bbp-move-reply_' . $move_reply->ID ) ) {
		bbp_add_error( 'bbp_move_reply_nonce', __( '<strong>ERROR</strong>: Are you sure you wanted to do that?', 'bbpress' ) );
		return;
	}

	// Use cannot edit topic
	if ( ! current_user_can( 'edit_topic', $source_topic->ID ) ) {
		bbp_add_error( 'bbp_move_reply_source_permission', __( '<strong>ERROR</strong>: You do not have permission to edit the source topic.', 'bbpress' ) );
	}

	// How to move
	if ( ! empty( $_POST['bbp_reply_move_option'] ) ) {
		$move_option = (string) trim( $_POST['bbp_reply_move_option'] );
	}

	// Invalid move option
	if ( empty( $move_option ) || ! in_array( $move_option, array( 'existing', 'topic' ), true ) ) {
		bbp_add_error( 'bbp_move_reply_option', __( '<strong>ERROR</strong>: You need to choose a valid move option.', 'bbpress' ) );

	// Valid move option
	} else {

		// What kind of move
		switch ( $move_option ) {

			// Into an existing topic
			case 'existing' :

				// Get destination topic id
				if ( empty( $_POST['bbp_destination_topic'] ) ) {
					bbp_add_error( 'bbp_move_reply_destination_id', __( '<strong>ERROR</strong>: A topic ID is required.', 'bbpress' ) );
				} else {
					$destination_topic_id = (int) $_POST['bbp_destination_topic'];
				}

				// Get the destination topic
				$destination_topic = bbp_get_topic( $destination_topic_id );

				// No destination topic
				if ( empty( $destination_topic ) ) {
					bbp_add_error( 'bbp_move_reply_destination_not_found', __( '<strong>ERROR</strong>: The topic you want to move to was not found.', 'bbpress' ) );
				}

				// User cannot edit the destination topic
				if ( ! current_user_can( 'edit_topic', $destination_topic->ID ) ) {
					bbp_add_error( 'bbp_move_reply_destination_permission', __( '<strong>ERROR</strong>: You do not have permission to edit the destination topic.', 'bbpress' ) );
				}

				// Bump the reply position
				$reply_position = bbp_get_topic_reply_count( $destination_topic->ID, true ) + 1;

				// Update the reply
				wp_update_post( array(
					'ID'          => $move_reply->ID,
					'post_title'  => '',
					'post_name'   => false, // will be automatically generated
					'post_parent' => $destination_topic->ID,
					'menu_order'  => $reply_position,
					'guid'        => ''
				) );

				// Adjust reply meta values
				bbp_update_reply_topic_id( $move_reply->ID, $destination_topic->ID );
				bbp_update_reply_forum_id( $move_reply->ID, bbp_get_topic_forum_id( $destination_topic->ID ) );

				break;

			// Move reply to a new topic
			case 'topic' :
			default :

				// User needs to be able to publish topics
				if ( current_user_can( 'publish_topics' ) ) {

					// Use the new title that was passed
					if ( ! empty( $_POST['bbp_reply_move_destination_title'] ) ) {
						$destination_topic_title = sanitize_text_field( $_POST['bbp_reply_move_destination_title'] );

					// Use the source topic title
					} else {
						$destination_topic_title = $source_topic->post_title;
					}

					// Update the topic
					$destination_topic_id = wp_update_post( array(
						'ID'          => $move_reply->ID,
						'post_title'  => $destination_topic_title,
						'post_name'   => false,
						'post_type'   => bbp_get_topic_post_type(),
						'post_parent' => $source_topic->post_parent,
						'guid'        => ''
					) );
					$destination_topic = bbp_get_topic( $destination_topic_id );

					// Make sure the new topic knows its a topic
					bbp_update_topic_topic_id( $move_reply->ID );

					// Shouldn't happen
					if ( false === $destination_topic_id || is_wp_error( $destination_topic_id ) || empty( $destination_topic ) ) {
						bbp_add_error( 'bbp_move_reply_destination_reply', __( '<strong>ERROR</strong>: There was a problem converting the reply into the topic. Please try again.', 'bbpress' ) );
					}

				// User cannot publish posts
				} else {
					bbp_add_error( 'bbp_move_reply_destination_permission', __( '<strong>ERROR</strong>: You do not have permission to create new topics. The reply could not be converted into a topic.', 'bbpress' ) );
				}

				break;
		}
	}

	// Bail if there are errors
	if ( bbp_has_errors() ) {
		return;
	}

	/** No Errors - Clean Up **************************************************/

	// Update counts, etc...
	do_action( 'bbp_pre_move_reply', $move_reply->ID, $source_topic->ID, $destination_topic->ID );

	/** Date Check ************************************************************/

	// Check if the destination topic is older than the move reply
	if ( strtotime( $move_reply->post_date ) < strtotime( $destination_topic->post_date ) ) {

		// Set destination topic post_date to 1 second before from reply
		$destination_post_date = date( 'Y-m-d H:i:s', strtotime( $move_reply->post_date ) - 1 );

		// Update destination topic
		wp_update_post( array(
			'ID'            => $destination_topic_id,
			'post_date'     => $destination_post_date,
			'post_date_gmt' => get_gmt_from_date( $destination_post_date )
		) );
	}

	// Set the last reply ID and freshness to the move_reply
	$last_reply_id = $move_reply->ID;
	$freshness     = $move_reply->post_date;

	// Get the reply to
	$parent = bbp_get_reply_to( $move_reply->ID );

	// Fix orphaned children
	$children = get_posts( array(
		'post_type'  => bbp_get_reply_post_type(),
		'meta_key'   => '_bbp_reply_to',
		'meta_type'  => 'NUMERIC',
		'meta_value' => $move_reply->ID,
	) );
	foreach ( $children as $child ) {
		bbp_update_reply_to( $child->ID, $parent );
	}

	// Remove reply_to from moved reply
	delete_post_meta( $move_reply->ID, '_bbp_reply_to' );

	// It is a new topic and we need to set some default metas to make
	// the topic display in bbp_has_topics() list
	if ( 'topic' === $move_option ) {
		bbp_update_topic_last_reply_id   ( $destination_topic->ID, $last_reply_id );
		bbp_update_topic_last_active_id  ( $destination_topic->ID, $last_reply_id );
		bbp_update_topic_last_active_time( $destination_topic->ID, $freshness     );

	// Otherwise update the existing destination topic
	} else {
		bbp_update_topic_last_reply_id   ( $destination_topic->ID );
		bbp_update_topic_last_active_id  ( $destination_topic->ID );
		bbp_update_topic_last_active_time( $destination_topic->ID );
	}

	// Update source topic ID last active
	bbp_update_topic_last_reply_id   ( $source_topic->ID );
	bbp_update_topic_last_active_id  ( $source_topic->ID );
	bbp_update_topic_last_active_time( $source_topic->ID );

	/** Successful Move ******************************************************/

	// Update counts, etc...
	do_action( 'bbp_post_move_reply', $move_reply->ID, $source_topic->ID, $destination_topic->ID );

	// Redirect back to the topic
	bbp_redirect( bbp_get_topic_permalink( $destination_topic->ID ) );
}

/**
 * Fix counts on reply move
 *
 * When a reply is moved, update the counts of source and destination topic
 * and their forums.
 *
 * @since 2.3.0 bbPress (r4521)
 *
 * @param int $move_reply_id Move reply id
 * @param int $source_topic_id Source topic id
 * @param int $destination_topic_id Destination topic id
 * @uses bbp_update_forum_topic_count() To update the forum topic counts
 * @uses bbp_update_forum_reply_count() To update the forum reply counts
 * @uses bbp_update_topic_reply_count() To update the topic reply counts
 * @uses bbp_update_topic_voice_count() To update the topic voice counts
 * @uses bbp_update_topic_reply_count_hidden() To update the topic hidden reply
 *                                              count
 * @uses do_action() Calls 'bbp_move_reply_count' with the move reply id,
 *                    source topic id & destination topic id
 */
function bbp_move_reply_count( $move_reply_id, $source_topic_id, $destination_topic_id ) {

	// Forum topic counts
	bbp_update_forum_topic_count( bbp_get_topic_forum_id( $destination_topic_id ) );

	// Forum reply counts
	bbp_update_forum_reply_count( bbp_get_topic_forum_id( $destination_topic_id ) );

	// Topic reply counts
	bbp_update_topic_reply_count( $source_topic_id      );
	bbp_update_topic_reply_count( $destination_topic_id );

	// Topic hidden reply counts
	bbp_update_topic_reply_count_hidden( $source_topic_id      );
	bbp_update_topic_reply_count_hidden( $destination_topic_id );

	// Topic voice counts
	bbp_update_topic_voice_count( $source_topic_id      );
	bbp_update_topic_voice_count( $destination_topic_id );

	do_action( 'bbp_move_reply_count', $move_reply_id, $source_topic_id, $destination_topic_id );
}

/** Reply Actions *************************************************************/

/**
 * Handles the front end spamming/unspamming and trashing/untrashing/deleting of
 * replies
 *
 * @since 2.0.0 bbPress (r2740)
 *
 * @param string $action The requested action to compare this function to
 * @uses bbp_get_reply() To get the reply
 * @uses current_user_can() To check if the user is capable of editing or
 *                           deleting the reply
 * @uses check_ajax_referer() To verify the nonce and check the referer
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses bbp_is_reply_spam() To check if the reply is marked as spam
 * @uses bbp_spam_reply() To make the reply as spam
 * @uses bbp_unspam_reply() To unmark the reply as spam
 * @uses wp_trash_post() To trash the reply
 * @uses wp_untrash_post() To untrash the reply
 * @uses wp_delete_post() To delete the reply
 * @uses do_action() Calls 'bbp_toggle_reply_handler' with success, post data
 *                    and action
 * @uses bbp_get_reply_url() To get the reply url
 * @uses bbp_redirect() To redirect to the reply
 * @uses bbPress::errors:add() To log the error messages
 */
function bbp_toggle_reply_handler( $action = '' ) {

	// Bail if required GET actions aren't passed
	if ( empty( $_GET['reply_id'] ) ) {
		return;
	}

	// What's the reply id?
	$reply_id = bbp_get_reply_id( (int) $_GET['reply_id'] );

	// Get possible reply-handler toggles
	$toggles = bbp_get_reply_toggles( $reply_id );

	// Bail if action isn't meant for this function
	if ( ! in_array( $action, $toggles, true ) ) {
		return;
	}

	// Make sure reply exists
	$reply = bbp_get_reply( $reply_id );
	if ( empty( $reply ) ) {
		bbp_add_error( 'bbp_toggle_reply_missing', __( '<strong>ERROR:</strong> This reply could not be found or no longer exists.', 'bbpress' ) );
		return;
	}

	// What is the user doing here?
	if ( ! current_user_can( 'edit_reply', $reply_id ) || ( 'bbp_toggle_reply_trash' === $action && ! current_user_can( 'delete_reply', $reply_id ) ) ) {
		bbp_add_error( 'bbp_toggle_reply_permission', __( '<strong>ERROR:</strong> You do not have permission to do that.', 'bbpress' ) );
		return;
	}

	// Sub-action?
	$sub_action = ! empty( $_GET['sub_action'] )
		? sanitize_key( $_GET['sub_action'] )
		: false;

	// Preliminary array
	$post_data = array( 'ID' => $reply_id );

	// Do the reply toggling
	$retval = bbp_toggle_reply( array(
		'id'         => $reply_id,
		'action'     => $action,
		'sub_action' => $sub_action,
		'data'       => $post_data
	) );

	// Do additional reply toggle actions
	do_action( 'bbp_toggle_reply_handler', $retval['status'], $post_data, $action );

	// Redirect back to reply
	if ( ( false !== $retval['status'] ) && ! is_wp_error( $retval['status'] ) ) {
		bbp_redirect( $retval['redirect_to'] );

	// Handle errors
	} else {
		bbp_add_error( 'bbp_toggle_reply', $retval['message'] );
	}
}

/**
 * Do the actual reply toggling
 *
 * This function is used by `bbp_toggle_reply_handler()` to do the actual heavy
 * lifting when it comes to toggling replies. It only really makes sense to call
 * within that context, so if you need to call this function directly, make sure
 * you're also doing what the handler does too.
 *
 * @since 2.6.0 bbPress (r6133)
 * @access private
 *
 * @param array $args
 */
function bbp_toggle_reply( $args = array() ) {

	// Parse the arguments
	$r = bbp_parse_args( $args, array(
		'id'         => 0,
		'action'     => '',
		'sub_action' => '',
		'data'       => array()
	) );

	// Build the nonce suffix
	$nonce_suffix = bbp_get_reply_post_type() . '_' . (int) $r['id'];

	// Default return values
	$retval = array(
		'status'      => 0,
		'message'     => '',
		'redirect_to' => bbp_get_reply_url( $r['id'], bbp_get_redirect_to() ),
		'view_all'    => false
	);

	// What action are we trying to perform?
	switch ( $r['action'] ) {

		// Toggle approve
		case 'bbp_toggle_reply_approve' :
			check_ajax_referer( "approve-{$nonce_suffix}" );

			$is_approve         = bbp_is_reply_pending( $r['id'] );
			$retval['status']   = $is_approve ? bbp_approve_reply( $r['id'] ) : bbp_unapprove_reply( $r['id'] );
			$retval['message']  = $is_approve ? __( '<strong>ERROR</strong>: There was a problem approving the reply.', 'bbpress' ) : __( '<strong>ERROR</strong>: There was a problem unapproving the reply.', 'bbpress' );
			$retval['view_all'] = ! $is_approve;

			break;

		// Toggle spam
		case 'bbp_toggle_reply_spam' :
			check_ajax_referer( "spam-{$nonce_suffix}" );

			$is_spam            = bbp_is_reply_spam( $r['id'] );
			$retval['status']   = $is_spam ? bbp_unspam_reply( $r['id'] ) : bbp_spam_reply( $r['id'] );
			$retval['message']  = $is_spam ? __( '<strong>ERROR</strong>: There was a problem unmarking the reply as spam.', 'bbpress' ) : __( '<strong>ERROR</strong>: There was a problem marking the reply as spam.', 'bbpress' );
			$retval['view_all'] = ! $is_spam;

			break;

		// Toggle trash
		case 'bbp_toggle_reply_trash' :

			// Which subaction?
			switch ( $r['sub_action'] ) {
				case 'trash':
					check_ajax_referer( "trash-{$nonce_suffix}" );

					$retval['view_all'] = true;
					$retval['status']   = wp_trash_post( $r['id'] );
					$retval['message']  = __( '<strong>ERROR</strong>: There was a problem trashing the reply.', 'bbpress' );

					break;

				case 'untrash':
					check_ajax_referer( "untrash-{$nonce_suffix}" );

					$retval['status']  = wp_untrash_post( $r['id'] );
					$retval['message'] = __( '<strong>ERROR</strong>: There was a problem untrashing the reply.', 'bbpress' );

					break;

				case 'delete':
					check_ajax_referer( "delete-{$nonce_suffix}" );

					$retval['status']  = wp_delete_post( $r['id'] );
					$retval['message'] = __( '<strong>ERROR</strong>: There was a problem deleting the reply.', 'bbpress' );

					break;
			}

			break;
	}

	// Add view all if needed
	if ( ! empty( $retval['view_all'] ) ) {
		$retval['redirect_to'] = bbp_add_view_all( $retval['redirect_to'], true );
	}

	// Filter & return
	return apply_filters( 'bbp_toggle_reply', $retval, $r, $args );
}

/** Helpers *******************************************************************/

/**
 * Return an associative array of available reply statuses
 *
 * @since 2.6.0 bbPress (r5399)
 *
 * @param int $reply_id   Optional. Reply id.
 *
 * @return array
 */
function bbp_get_reply_statuses( $reply_id = 0 ) {

	// Filter & return
	return (array) apply_filters( 'bbp_get_reply_statuses', array(
		bbp_get_public_status_id()  => _x( 'Publish', 'Publish the reply',     'bbpress' ),
		bbp_get_spam_status_id()    => _x( 'Spam',    'Spam the reply',        'bbpress' ),
		bbp_get_trash_status_id()   => _x( 'Trash',   'Trash the reply',       'bbpress' ),
		bbp_get_pending_status_id() => _x( 'Pending', 'Mark reply as pending', 'bbpress' ),
	), $reply_id );
}

/**
 * Return array of available reply toggle actions
 *
 * @since 2.6.0 bbPress (r6133)
 *
 * @param int $reply_id   Optional. Reply id.
 *
 * @return array
 */
function bbp_get_reply_toggles( $reply_id = 0 ) {

	// Filter & return
	return (array) apply_filters( 'bbp_get_toggle_reply_actions', array(
		'bbp_toggle_reply_spam',
		'bbp_toggle_reply_trash',
		'bbp_toggle_reply_approve'
	), $reply_id );
}

/** Reply Actions *************************************************************/

/**
 * Marks a reply as spam
 *
 * @since 2.0.0 bbPress (r2740)
 *
 * @param int $reply_id Reply id
 * @uses bbp_get_reply() To get the reply
 * @uses do_action() Calls 'bbp_spam_reply' with the reply ID
 * @uses add_post_meta() To add the previous status to a meta
 * @uses wp_update_post() To insert the updated post
 * @uses do_action() Calls 'bbp_spammed_reply' with the reply ID
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_spam_reply( $reply_id = 0 ) {

	// Get reply
	$reply = bbp_get_reply( $reply_id );
	if ( empty( $reply ) ) {
		return $reply;
	}

	// Bail if already spam
	if ( bbp_get_spam_status_id() === $reply->post_status ) {
		return false;
	}

	// Execute pre spam code
	do_action( 'bbp_spam_reply', $reply_id );

	// Add the original post status as post meta for future restoration
	add_post_meta( $reply_id, '_bbp_spam_meta_status', $reply->post_status );

	// Set post status to spam
	$reply->post_status = bbp_get_spam_status_id();

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update the reply
	$reply_id = wp_update_post( $reply );

	// Execute post spam code
	do_action( 'bbp_spammed_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/**
 * Unspams a reply
 *
 * @since 2.0.0 bbPress (r2740)
 *
 * @param int $reply_id Reply id
 * @uses bbp_get_reply() To get the reply
 * @uses do_action() Calls 'bbp_unspam_reply' with the reply ID
 * @uses get_post_meta() To get the previous status meta
 * @uses delete_post_meta() To delete the previous status meta
 * @uses wp_update_post() To insert the updated post
 * @uses do_action() Calls 'bbp_unspammed_reply' with the reply ID
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_unspam_reply( $reply_id = 0 ) {

	// Get reply
	$reply = bbp_get_reply( $reply_id );
	if ( empty( $reply ) ) {
		return $reply;
	}

	// Bail if already not spam
	if ( bbp_get_spam_status_id() !== $reply->post_status ) {
		return false;
	}

	// Execute pre unspam code
	do_action( 'bbp_unspam_reply', $reply_id );

	// Get pre spam status
	$reply->post_status = get_post_meta( $reply_id, '_bbp_spam_meta_status', true );

	// If no previous status, default to publish
	if ( empty( $reply->post_status ) ) {
		$reply->post_status = bbp_get_public_status_id();
	}

	// Delete pre spam meta
	delete_post_meta( $reply_id, '_bbp_spam_meta_status' );

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update the reply
	$reply_id = wp_update_post( $reply );

	// Execute post unspam code
	do_action( 'bbp_unspammed_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/**
 * Approves a reply
 *
 * @since 2.6.0 bbPress (r5506)
 *
 * @param int $reply_id Reply id
 * @uses bbp_get_reply() To get the reply
 * @uses bbp_get_pending_status_id() To get the pending status id
 * @uses do_action() Calls 'bbp_approve_reply' with the reply id
 * @uses bbp_get_public_status_id() To get the public status id
 * @uses remove_action() To remove the auto save post revision action
 * @uses wp_update_post() To update the reply with the new status
 * @uses do_action() Calls 'bbp_approved_reply' with the reply id
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_approve_reply( $reply_id = 0 ) {

	// Get reply
	$reply = bbp_get_reply( $reply_id );
	if ( empty( $reply ) ) {
		return $reply;
	}

	// Bail if already approved
	if ( bbp_get_pending_status_id() !== $reply->post_status ) {
		return false;
	}

	// Execute pre pending code
	do_action( 'bbp_approve_reply', $reply_id );

	// Set publish status
	$reply->post_status = bbp_get_public_status_id();

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update reply
	$reply_id = wp_update_post( $reply );

	// Execute post pending code
	do_action( 'bbp_approved_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/**
 * Unapproves a reply
 *
 * @since 2.6.0 bbPress (r5506)
 *
 * @param int $reply_id Reply id
 * @uses bbp_get_reply() To get the reply
 * @uses bbp_get_pending_status_id() To get the pending status id
 * @uses do_action() Calls 'bbp_unapprove_reply' with the reply id
 * @uses remove_action() To remove the auto save post revision action
 * @uses wp_update_post() To update the reply with the new status
 * @uses do_action() Calls 'bbp_unapproved_reply' with the reply id
 * @return mixed False or {@link WP_Error} on failure, reply id on success
 */
function bbp_unapprove_reply( $reply_id = 0 ) {

	// Get reply
	$reply = bbp_get_reply( $reply_id );
	if ( empty( $reply ) ) {
		return $reply;
	}

	// Bail if already pending
	if ( bbp_get_pending_status_id() === $reply->post_status ) {
		return false;
	}

	// Execute pre open code
	do_action( 'bbp_unapprove_reply', $reply_id );

	// Set pending status
	$reply->post_status = bbp_get_pending_status_id();

	// No revisions
	remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Update reply
	$reply_id = wp_update_post( $reply );

	// Execute post open code
	do_action( 'bbp_unapproved_reply', $reply_id );

	// Return reply_id
	return $reply_id;
}

/** Before Delete/Trash/Untrash ***********************************************/

/**
 * Called before deleting a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_delete_reply' with the reply id
 */
function bbp_delete_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_delete_reply', $reply_id );
}

/**
 * Called before trashing a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_trash_reply' with the reply id
 */
function bbp_trash_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_trash_reply', $reply_id );
}

/**
 * Called before untrashing (restoring) a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_unstrash_reply' with the reply id
 */
function bbp_untrash_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_untrash_reply', $reply_id );
}

/** After Delete/Trash/Untrash ************************************************/

/**
 * Called after deleting a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_deleted_reply' with the reply id
 */
function bbp_deleted_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_deleted_reply', $reply_id );
}

/**
 * Called after trashing a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_trashed_reply' with the reply id
 */
function bbp_trashed_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_trashed_reply', $reply_id );
}

/**
 * Called after untrashing (restoring) a reply
 *
 * @uses bbp_get_reply_id() To get the reply id
 * @uses bbp_is_reply() To check if the passed id is a reply
 * @uses do_action() Calls 'bbp_untrashed_reply' with the reply id
 */
function bbp_untrashed_reply( $reply_id = 0 ) {
	$reply_id = bbp_get_reply_id( $reply_id );

	if ( empty( $reply_id ) || ! bbp_is_reply( $reply_id ) ) {
		return false;
	}

	do_action( 'bbp_untrashed_reply', $reply_id );
}

/** Settings ******************************************************************/

/**
 * Return the replies per page setting
 *
 * @since 2.0.0 bbPress (r3540)
 *
 * @param int $default Default replies per page (15)
 * @uses get_option() To get the setting
 * @uses apply_filters() To allow the return value to be manipulated
 * @return int
 */
function bbp_get_replies_per_page( $default = 15 ) {

	// Get database option and cast as integer
	$retval = get_option( '_bbp_replies_per_page', $default );

	// If return val is empty, set it to default
	if ( empty( $retval ) ) {
		$retval = $default;
	}

	// Filter & return
	return (int) apply_filters( 'bbp_get_replies_per_page', $retval, $default );
}

/**
 * Return the replies per RSS page setting
 *
 * @since 2.0.0 bbPress (r3540)
 *
 * @param int $default Default replies per page (25)
 * @uses get_option() To get the setting
 * @uses apply_filters() To allow the return value to be manipulated
 * @return int
 */
function bbp_get_replies_per_rss_page( $default = 25 ) {

	// Get database option and cast as integer
	$retval = get_option( '_bbp_replies_per_rss_page', $default );

	// If return val is empty, set it to default
	if ( empty( $retval ) ) {
		$retval = $default;
	}

	// Filter & return
	return (int) apply_filters( 'bbp_get_replies_per_rss_page', $retval, $default );
}

/** Autoembed *****************************************************************/

/**
 * Check if autoembeds are enabled and hook them in if so
 *
 * @since 2.1.0 bbPress (r3752)
 *
 * @global WP_Embed $wp_embed
 */
function bbp_reply_content_autoembed() {
	global $wp_embed;

	if ( bbp_use_autoembed() && is_a( $wp_embed, 'WP_Embed' ) ) {
		add_filter( 'bbp_get_reply_content', array( $wp_embed, 'autoembed' ), 2 );
	}
}

/** Filters *******************************************************************/

/**
 * Used by bbp_has_replies() to add the lead topic post to the posts loop
 *
 * This function filters the 'post_where' of the WP_Query, and changes the query
 * to include both the topic AND its children in the same loop.
 *
 * @since 2.1.0 bbPress (r4058)
 *
 * @param string $where
 * @return string
 */
function _bbp_has_replies_where( $where = '', $query = false ) {

	/** Bail ******************************************************************/

	// Bail if the sky is falling
	if ( empty( $where ) || empty( $query ) ) {
		return $where;
	}

	// Bail if no post_parent to replace
	if ( ! is_numeric( $query->get( 'post_parent' ) ) ) {
		return $where;
	}

	// Bail if not a topic and reply query
	if ( array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ) !== $query->get( 'post_type' ) ) {
		return $where;
	}

	// Bail if including specific post ID's
	if ( $query->get( 'post__in' ) ) {
		return $where;
	}

	/** Proceed ***************************************************************/

	// Table name for posts
	$table_name = bbp_db()->prefix . 'posts';

	// Get the topic ID from the post_parent, set in bbp_has_replies()
	$topic_id   = bbp_get_topic_id( $query->get( 'post_parent' ) );

	// The texts to search for
	$search     = array(
		"FROM {$table_name} " ,
		"WHERE 1=1  AND {$table_name}.post_parent = {$topic_id}",
		") AND {$table_name}.post_parent = {$topic_id}"
	);

	// The texts to replace them with
	$replace     = array(
		$search[0] . "FORCE INDEX (PRIMARY, post_parent) " ,
		"WHERE 1=1 AND ({$table_name}.ID = {$topic_id} OR {$table_name}.post_parent = {$topic_id})",
		") AND ({$table_name}.ID = {$topic_id} OR {$table_name}.post_parent = {$topic_id})"
	);

	// Try to replace the search text with the replacement
	$new_where = str_replace( $search, $replace, $where );
	if ( ! empty( $new_where ) ) {
		$where = $new_where;
	}

	return $where;
}

/** Feeds *********************************************************************/

/**
 * Output an RSS2 feed of replies, based on the query passed.
 *
 * @since 2.0.0 bbPress (r3171)
 *
 * @uses bbp_version()
 * @uses bbp_is_single_topic()
 * @uses bbp_user_can_view_forum()
 * @uses bbp_get_topic_forum_id()
 * @uses bbp_show_lead_topic()
 * @uses bbp_topic_permalink()
 * @uses bbp_topic_title()
 * @uses bbp_get_topic_reply_count()
 * @uses bbp_topic_content()
 * @uses bbp_has_replies()
 * @uses bbp_replies()
 * @uses bbp_the_reply()
 * @uses bbp_reply_url()
 * @uses bbp_reply_title()
 * @uses bbp_reply_content()
 * @uses get_wp_title_rss()
 * @uses get_bloginfo_rss()
 * @uses get_option()
 * @uses self_link()
 * @uses the_author()
 * @uses get_post_time()
 * @uses rss_enclosure()
 * @uses do_action()
 * @uses apply_filters()
 *
 * @param array $replies_query
 */
function bbp_display_replies_feed_rss2( $replies_query = array() ) {

	// User cannot access forum this topic is in
	if ( bbp_is_single_topic() && ! bbp_user_can_view_forum( array( 'forum_id' => bbp_get_topic_forum_id() ) ) ) {
		return;
	}

	// Adjust the title based on context
	if ( bbp_is_single_topic() ) {
		$title = get_wp_title_rss();
	} elseif ( ! bbp_show_lead_topic() ) {
		$title = get_bloginfo_rss( 'name' ) . ' &#187; ' .  __( 'All Posts',   'bbpress' );
	} else {
		$title = get_bloginfo_rss( 'name' ) . ' &#187; ' .  __( 'All Replies', 'bbpress' );
	}

	$title = apply_filters( 'wp_title_rss', $title );

	// Display the feed
	header( 'Content-Type: ' . feed_content_type( 'rss2' ) . '; charset=' . get_option( 'blog_charset' ), true );
	header( 'Status: 200 OK' );
	echo '<?xml version="1.0" encoding="' . get_option( 'blog_charset' ) . '"?' . '>'; ?>

	<rss version="2.0"
		xmlns:content="http://purl.org/rss/1.0/modules/content/"
		xmlns:wfw="http://wellformedweb.org/CommentAPI/"
		xmlns:dc="http://purl.org/dc/elements/1.1/"
		xmlns:atom="http://www.w3.org/2005/Atom"

		<?php do_action( 'bbp_feed' ); ?>
	>

	<channel>

		<title><?php echo $title; ?></title>
		<atom:link href="<?php self_link(); ?>" rel="self" type="application/rss+xml" />
		<link><?php self_link(); ?></link>
		<description><?php //?></description>
		<lastBuildDate><?php echo date( 'r' ); ?></lastBuildDate>
		<generator>https://bbpress.org/?v=<?php bbp_version(); ?></generator>
		<language><?php bloginfo_rss( 'language' ); ?></language>

		<?php do_action( 'bbp_feed_head' ); ?>

		<?php if ( bbp_is_single_topic() ) : ?>
			<?php if ( bbp_user_can_view_forum( array( 'forum_id' => bbp_get_topic_forum_id() ) ) ) : ?>
				<?php if ( bbp_show_lead_topic() ) : ?>

					<item>
						<guid><?php bbp_topic_permalink(); ?></guid>
						<title><![CDATA[<?php bbp_topic_title(); ?>]]></title>
						<link><?php bbp_topic_permalink(); ?></link>
						<pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ); ?></pubDate>
						<dc:creator><?php the_author(); ?></dc:creator>

						<description>
							<![CDATA[
							<p><?php printf( __( 'Replies: %s', 'bbpress' ), bbp_get_topic_reply_count() ); ?></p>
							<?php bbp_topic_content(); ?>
							]]>
						</description>

						<?php rss_enclosure(); ?>

						<?php do_action( 'bbp_feed_item' ); ?>

					</item>

				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( bbp_has_replies( $replies_query ) ) : ?>
			<?php while ( bbp_replies() ) : bbp_the_reply(); ?>

				<item>
					<guid><?php bbp_reply_url(); ?></guid>
					<title><![CDATA[<?php bbp_reply_title(); ?>]]></title>
					<link><?php bbp_reply_url(); ?></link>
					<pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ); ?></pubDate>
					<dc:creator><?php the_author() ?></dc:creator>

					<description>
						<![CDATA[
						<?php bbp_reply_content(); ?>
						]]>
					</description>

					<?php rss_enclosure(); ?>

					<?php do_action( 'bbp_feed_item' ); ?>

				</item>

			<?php endwhile; ?>
		<?php endif; ?>

		<?php do_action( 'bbp_feed_footer' ); ?>

	</channel>
	</rss>

<?php

	// We're done here
	exit();
}

/** Permissions ***************************************************************/

/**
 * Redirect if unathorized user is attempting to edit a reply
 *
 * @since 2.1.0 bbPress (r3605)
 *
 * @uses bbp_is_reply_edit()
 * @uses current_user_can()
 * @uses bbp_get_topic_id()
 * @uses bbp_redirect()
 * @uses bbp_get_topic_permalink()
 */
function bbp_check_reply_edit() {

	// Bail if not editing a topic
	if ( ! bbp_is_reply_edit() ) {
		return;
	}

	// User cannot edit topic, so redirect back to reply
	if ( ! current_user_can( 'edit_reply', bbp_get_reply_id() ) ) {
		bbp_redirect( bbp_get_reply_url() );
	}
}

/** Reply Position ************************************************************/

/**
 * Update the position of the reply.
 *
 * The reply position is stored in the menu_order column of the posts table.
 * This is done to prevent using a meta_query to retrieve posts in the proper
 * freshness order. By updating the menu_order accordingly, we're able to
 * leverage core WordPress query ordering much more effectively.
 *
 * @since 2.1.0 bbPress (r3933)
 *
 * @param int $reply_id
 * @param int $reply_position
 *
 * @return mixed
 */
function bbp_update_reply_position( $reply_id = 0, $reply_position = 0 ) {

	// Bail if reply_id is empty
	$reply_id = bbp_get_reply_id( $reply_id );
	if ( empty( $reply_id ) ) {
		return false;
	}

	// If no position was passed, get it from the db and update the menu_order
	if ( empty( $reply_position ) ) {
		$reply_position = bbp_get_reply_position_raw( $reply_id, bbp_get_reply_topic_id( $reply_id ) );
	}

	// Toggle revisions off as we are not altering content
	if ( post_type_supports( bbp_get_reply_post_type(), 'revisions' ) ) {
		$revisions_removed = true;
		remove_post_type_support( bbp_get_reply_post_type(), 'revisions' );
	}

	// Update the replies' 'menu_order' with the reply position
	wp_update_post( array(
		'ID'         => $reply_id,
		'menu_order' => $reply_position
	) );

	// Toggle revisions back on
	if ( true === $revisions_removed ) {
		$revisions_removed = false;
		add_post_type_support( bbp_get_reply_post_type(), 'revisions' );
	}

	return (int) $reply_position;
}

/**
 * Get the position of a reply by querying the DB directly for the replies
 * of a given topic.
 *
 * @since 2.1.0 bbPress (r3933)
 *
 * @param int $reply_id
 * @param int $topic_id
 */
function bbp_get_reply_position_raw( $reply_id = 0, $topic_id = 0 ) {

	// Get required data
	$reply_id       = bbp_get_reply_id( $reply_id );
	$topic_id       = ! empty( $topic_id ) ? bbp_get_topic_id( $topic_id ) : bbp_get_reply_topic_id( $reply_id );
	$reply_position = 0;

	// If reply is actually the first post in a topic, return 0
	if ( $reply_id !== $topic_id ) {

		// Make sure the topic has replies before running another query
		$reply_count = bbp_get_topic_reply_count( $topic_id, false );
		if ( ! empty( $reply_count ) ) {

			// Get reply id's
			$topic_replies = bbp_get_all_child_ids( $topic_id, bbp_get_reply_post_type() );
			if ( ! empty( $topic_replies ) ) {

				// Reverse replies array and search for current reply position
				$topic_replies  = array_reverse( $topic_replies );
				$reply_position = array_search( (string) $reply_id, $topic_replies );

				// Bump the position to compensate for the lead topic post
				$reply_position++;
			}
		}
	}

	return (int) $reply_position;
}

/** Hierarchical Replies ******************************************************/

/**
 * Are replies threaded?
 *
 * @since 2.4.0 bbPress (r4944)
 * @since 2.6.0 bbPress (r6245) Always false on user profile reply pages
 *
 * @param bool $default Optional. Default value true
 * @uses apply_filters() Calls 'bbp_thread_replies' with the calculated value and
 *                        the thread replies depth
 * @uses get_option() To get thread replies option
 * @return bool Are replies threaded?
 */
function bbp_thread_replies() {
	$depth = bbp_thread_replies_depth();
	$allow = bbp_allow_threaded_replies();

	// Never thread replies on user profile pages. It looks weird, and we know
	// it is undesirable for the majority of installations.
	if ( bbp_is_single_user_replies() ) {
		$retval = false;
	} else {
		$retval = (bool) ( ( $depth >= 2 ) && ( true === $allow ) );
	}

	// Filter & return
	return (bool) apply_filters( 'bbp_thread_replies', $retval, $depth, $allow );
}

/**
 * List threaded replies
 *
 * @since 2.4.0 bbPress (r4944)
 */
function bbp_list_replies( $args = array() ) {

	// Get bbPress
	$bbp = bbpress();

	// Reset the reply depth
	$bbp->reply_query->reply_depth = 0;

	// In reply loop
	$bbp->reply_query->in_the_loop = true;

	// Parse arguments
	$r = bbp_parse_args( $args, array(
		'walker'       => new BBP_Walker_Reply,
		'max_depth'    => bbp_thread_replies_depth(),
		'style'        => 'ul',
		'callback'     => null,
		'end_callback' => null,
		'page'         => 1,
		'per_page'     => -1
	), 'list_replies' );

	// Get replies to loop through in $_replies
	echo '<ul>' . $r['walker']->paged_walk( $bbp->reply_query->posts, $r['max_depth'], $r['page'], $r['per_page'], $r ) . '</ul>';

	$bbp->max_num_pages            = $r['walker']->max_pages;
	$bbp->reply_query->in_the_loop = false;
}

/**
 * Validate a `reply_to` field for hierarchical replies
 *
 * Checks for 2 scenarios:
 * -- The reply to ID is actually a reply
 * -- The reply to ID does not match the current reply
 *
 * @see https://bbpress.trac.wordpress.org/ticket/2588
 * @see https://bbpress.trac.wordpress.org/ticket/2586
 *
 * @since 2.5.4 bbPress (r5377)
 *
 * @param int $reply_to
 * @param int $reply_id
 *
 * @return int $reply_to
 */
function bbp_validate_reply_to( $reply_to = 0, $reply_id = 0 ) {

	// The parent reply must actually be a reply
	if ( ! bbp_is_reply( $reply_to ) ) {
		$reply_to = 0;
	}

	// The parent reply cannot be itself
	if ( $reply_id === $reply_to ) {
		$reply_to = 0;
	}

	return (int) $reply_to;
}
