/*jshint sub:true*/
/* global document, jQuery, ajaxurl, BBP_Converter */
jQuery( document ).ready( function ( $ ) {
	'use strict';

	// Variables
	var message  = $( '#bbp-converter-message'  ),
		stop     = $( '#bbp-converter-stop'     ),
		start    = $( '#bbp-converter-start'    ),
		restart  = $( '#_bbp_converter_restart' ),
		status   = $( '#bbp-converter-status'   ),
		settings = $( '#bbp-converter-settings' );

	/**
	 * Start button click
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @param {element} e
	 */
	start.on( 'click', function( e ) {
		bbp_converter_user_start();
		e.preventDefault();
	} );

	/**
	 * Stop button click
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @param {element} e
	 */
	$( stop ).on( 'click', function( e ) {
		bbp_converter_user_stop();
		e.preventDefault();
	} );

	/**
	 * Start the converter
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @returns {void}
	 */
	function bbp_converter_user_start() {
		bbp_converter_start();
	}

	/**
	 * Stop the converter
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @returns {void}
	 */
	function bbp_converter_user_stop() {
		bbp_converter_stop(
			BBP_Converter.strings.button_continue,
			BBP_Converter.strings.import_stopped_user
		);
	}

	/**
	 * Return values of converter settings
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @returns {converterL#2.bbp_converter_settings.values}
	 */
	function bbp_converter_settings() {
		var values = {};

		$.each( settings.serializeArray(), function( i, field ) {
			values[ field.name ] = field.value;
		} );

		if ( values['_bbp_converter_restart'] ) {
			restart.removeAttr( 'checked' );
		}

		if ( values['_bbp_converter_delay_time'] ) {
			BBP_Converter.delay = parseInt( values['_bbp_converter_delay_time'], 10 ) * 1000;
		}

		values['action']      = 'bbp_converter_process';
		values['_ajax_nonce'] = BBP_Converter.ajax_nonce;

		return values;
	}

	/**
	 * Run the converter step
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @returns {void}
	 */
	function bbp_converter_post() {
		$.post( ajaxurl, bbp_converter_settings(), function( response ) {

			// Parse the json response
			try {
				var data = response.data;

				// Success
				if ( true === response.success ) {
					bbp_converter_step( data );

				// Failure
				} else {
					bbp_converter_stop();
				}

			} catch( e ) {
				bbp_converter_stop();
			}
		}, 'json' );
	}

	/**
	 * Process the next step
	 *
	 * @since 2.6.0 bbPress (r6600)
	 *
	 * @param {object} data
	 * @returns {void}
	 */
	function bbp_converter_step( data ) {

		// Bail if not running
		if ( ! BBP_Converter.running ) {
			return;
		}

		// Do the step
		bbp_converter_log( data.progress );
		bbp_converter_status( data );
		bbp_converter_wait();

		// Done
		if ( data.current_step === data.final_step ) {
			bbp_converter_stop(
				BBP_Converter.strings.button_start,
				BBP_Converter.strings.import_complete
			);
		}
	}

	/**
	 * Wait to do the next AJAX request
	 *
	 * @since 2.6.0 bbPress (r6600)
	 *
	 * @returns {void}
	 */
	function bbp_converter_wait() {
		clearTimeout( BBP_Converter.running );

		// Bail if not running
		if ( ! BBP_Converter.running ) {
			return;
		}

		// Wait, then POST
		BBP_Converter.running = setTimeout( function() {
			bbp_converter_post();
		}, parseInt( BBP_Converter.delay, 10 ) );
	}

	/**
	 * Start the converter and set the various flags
	 *
	 * @since 2.6.0 bbPress (r6600)
	 *
	 * @returns {void}
	 */
	function bbp_converter_start() {
		clearTimeout( BBP_Converter.running );
		clearInterval( BBP_Converter.status );

		BBP_Converter.running = true;

		var log = BBP_Converter.strings.start_continue;
		if ( BBP_Converter.started ) {
			log = BBP_Converter.strings.start_start;
			BBP_Converter.started = true;
		}

		bbp_converter_update(
			BBP_Converter.strings.button_continue,
			log,
			BBP_Converter.strings.status_starting
		);

		message.addClass( 'started' );
		start.hide();
		stop.show();

		bbp_converter_post();
	}

	/**
	 * Stop the converter, and update the UI
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @param {string} button New text for button
	 * @param {string} log    New text to add to import monitor
	 *
	 * @returns {void}
	 */
	function bbp_converter_stop( button, log ) {
		clearTimeout( BBP_Converter.running );
		clearInterval( BBP_Converter.status );

		BBP_Converter.running = false;
		BBP_Converter.status  = false;

		if ( ! button ) {
			button = BBP_Converter.strings.button_continue;
		}

		if ( ! log ) {
			log = BBP_Converter.strings.status_stopped;
		}

		bbp_converter_update(
			button,
			log,
			BBP_Converter.strings.status_stopped
		);

		start.show();
		stop.hide();
	}

	/**
	 * Update the various screen texts
	 *
	 * @since 2.6.0 bbPress (r6600)
	 *
	 * @param {string} b_text
	 * @param {string} p_text
	 * @param {string} s_text
	 *
	 * @returns {void}
	 */
	function bbp_converter_update( b_text, p_text, s_text ) {
		start.val( b_text );
		bbp_converter_log( p_text );
		status.text( s_text );
	}

	/**
	 * Update the status
	 *
	 * @since 2.6.0 bbPress (r6513)
	 *
	 * @returns {void}
	 */
	function bbp_converter_status( data ) {
		var remaining = parseInt( BBP_Converter.delay, 10 ) / 1000,
			step      = parseInt( data.current_step,   10 ) + 1;

		status.text( BBP_Converter.strings.status_counting.replace( '%s', remaining ) );
		clearInterval( BBP_Converter.status );

		BBP_Converter.status = setInterval( function() {
			remaining--;
			status.text( BBP_Converter.strings.status_counting.replace( '%s', remaining ) );

			if ( remaining <= 0 ) {
				clearInterval( BBP_Converter.status );

				if ( parseInt( data.current_step, 10 ) < parseInt( data.final_step, 10 ) ) {
					status.text( BBP_Converter.strings.status_up_next.replace( '%s', step ) );
				} else {
					status.text( BBP_Converter.strings.status_complete );
				}
			}
		}, 1000 );
	}

	/**
	 * Prepend some text to the import monitor
	 *
	 * @since 2.6.0 bbPress (r6470)
	 *
	 * @param {string} text Text to prepend to the import monitor
	 *
	 * @returns {void}
	 */
	function bbp_converter_log( text ) {
		text = '<p>' + text + '</p>';

		message.prepend( text );
	}
} );
