<?php
/**
 * Mail Log storage for Bulwark JMAP Mail.
 *
 * @package Bulwark_JMAP_Mail
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulwark_JMAP_Mail_Log {

	const OPTION_NAME = 'bulwark_jmap_mail_log';
	const MAX_ENTRIES = 100;

	/**
	 * Add a mail log entry.
	 *
	 * @param array $entry Log entry data.
	 * @return void
	 */
	public static function add( $entry ) {
		$entries = self::get_entries();

		$normalized = array(
			'timestamp'        => (int) current_time( 'timestamp', true ),
			'status'           => ( isset( $entry['status'] ) && 'sent' === $entry['status'] ) ? 'sent' : 'failed',
			'to'               => sanitize_text_field( $entry['to'] ?? '' ),
			'from'             => sanitize_email( $entry['from'] ?? '' ),
			'subject'          => sanitize_text_field( wp_strip_all_tags( $entry['subject'] ?? '' ) ),
			'error'            => sanitize_text_field( $entry['error'] ?? '' ),
			'attachment_count' => isset( $entry['attachment_count'] ) ? max( 0, (int) $entry['attachment_count'] ) : 0,
			'account_id'       => sanitize_text_field( $entry['account_id'] ?? '' ),
			'identity_id'      => sanitize_text_field( $entry['identity_id'] ?? '' ),
			'email_id'         => sanitize_text_field( $entry['email_id'] ?? '' ),
		);

		array_unshift( $entries, $normalized );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Get stored mail log entries.
	 *
	 * @return array
	 */
	public static function get_entries() {
		$entries = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values( array_filter( $entries, 'is_array' ) );
	}

	/**
	 * Clear all stored mail log entries.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_NAME );
	}
}