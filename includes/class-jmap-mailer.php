<?php
/**
 * JMAP Mailer - Hooks into wp_mail to send via JMAP.
 *
 * @package Bulwark_JMAP_Mail
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulwark_JMAP_Mailer {

	private $options;

	public function __construct( $options ) {
		$this->options = $options;
		add_filter( 'pre_wp_mail', array( $this, 'send_via_jmap' ), 10, 2 );
	}

	/**
	 * Intercept wp_mail and send via JMAP instead.
	 *
	 * @param null|bool $return Short-circuit return value.
	 * @param array     $atts   wp_mail arguments.
	 * @return bool|null
	 */
	public function send_via_jmap( $return, $atts ) {
		$to      = $atts['to'];
		$subject = $atts['subject'];
		$message = $atts['message'];
		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		// Parse headers.
		$parsed = $this->parse_headers( $headers );

		$from_name  = ! empty( $parsed['from_name'] )  ? $parsed['from_name']  : $this->options['from_name'];
		$from_email = ! empty( $parsed['from_email'] ) ? $parsed['from_email'] : $this->options['from_email'];
		$cc         = $parsed['cc'];
		$bcc        = $parsed['bcc'];
		$reply_to   = $parsed['reply_to'];
		$content_type = $parsed['content_type'];

		// Normalize recipients.
		$to_addresses  = $this->normalize_addresses( $to );
		$cc_addresses  = $this->normalize_addresses( $cc );
		$bcc_addresses = $this->normalize_addresses( $bcc );
		$reply_to_addresses = $this->normalize_addresses( $reply_to );

		if ( empty( $to_addresses ) ) {
			return false;
		}

		// Build the JMAP client.
		$client = new Bulwark_JMAP_Client(
			$this->options['server_url'],
			$this->options['username'],
			$this->options['password']
		);

		$session_result = $client->discover_session();
		if ( is_wp_error( $session_result ) ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $session_result->get_error_message() ) );
			return false;
		}

		// Get identity.
		$identity_id = $client->get_identity_id( $from_email );
		if ( is_wp_error( $identity_id ) ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $identity_id->get_error_message() ) );
			return false;
		}

		$account_id = $client->get_account_id();

		// Resolve the Sent mailbox to store the outgoing email.
		$sent_mailbox_id = $client->get_mailbox_id_by_role( 'sent' );
		if ( is_wp_error( $sent_mailbox_id ) ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $sent_mailbox_id->get_error_message() ) );
			return false;
		}

		// Upload attachments if any.
		$jmap_attachments = array();
		foreach ( $attachments as $attachment ) {
			if ( ! is_string( $attachment ) || ! file_exists( $attachment ) ) {
				continue;
			}
			$file_data = file_get_contents( $attachment );
			if ( false === $file_data ) {
				continue;
			}
			$mime_type = wp_check_filetype( $attachment );
			$type = ! empty( $mime_type['type'] ) ? $mime_type['type'] : 'application/octet-stream';

			$blob_id = $client->upload_blob( $file_data, $type );
			if ( is_wp_error( $blob_id ) ) {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $blob_id->get_error_message() ) );
				return false;
			}

			$jmap_attachments[] = array(
				'blobId'      => $blob_id,
				'type'        => $type,
				'name'        => basename( $attachment ),
				'disposition' => 'attachment',
			);
		}

		// Build the email object.
		$email_create_id = 'wp-' . wp_generate_password( 12, false );
		$is_html = ( 'text/html' === $content_type );

		$email_object = array(
			'from'       => array( array( 'name' => $from_name, 'email' => $from_email ) ),
			'to'         => $this->format_jmap_addresses( $to_addresses ),
			'subject'    => $subject,
			'keywords'   => array( '$seen' => true ),
			'mailboxIds' => array( $sent_mailbox_id => true ),
		);

		if ( ! empty( $cc_addresses ) ) {
			$email_object['cc'] = $this->format_jmap_addresses( $cc_addresses );
		}
		if ( ! empty( $bcc_addresses ) ) {
			$email_object['bcc'] = $this->format_jmap_addresses( $bcc_addresses );
		}
		if ( ! empty( $reply_to_addresses ) ) {
			$email_object['replyTo'] = $this->format_jmap_addresses( $reply_to_addresses );
		}

		if ( $is_html ) {
			// Send as multipart with plain text fallback.
			$plain_text = wp_strip_all_tags( $message );
			$email_object['bodyValues'] = array(
				'text' => array( 'value' => $plain_text ),
				'html' => array( 'value' => $message ),
			);
			$email_object['textBody'] = array( array( 'partId' => 'text', 'type' => 'text/plain' ) );
			$email_object['htmlBody'] = array( array( 'partId' => 'html', 'type' => 'text/html' ) );
		} else {
			$email_object['bodyValues'] = array(
				'text' => array( 'value' => $message ),
			);
			$email_object['textBody'] = array( array( 'partId' => 'text', 'type' => 'text/plain' ) );
		}

		if ( ! empty( $jmap_attachments ) ) {
			$email_object['attachments'] = $jmap_attachments;
		}

		// Build the method calls: Email/set + EmailSubmission/set.
		$method_calls = array(
			array(
				'Email/set',
				array(
					'accountId' => $account_id,
					'create'    => array(
						$email_create_id => $email_object,
					),
				),
				'0',
			),
			array(
				'EmailSubmission/set',
				array(
					'accountId' => $account_id,
					'create'    => array(
						'sub-1' => array(
							'#emailId'   => array(
								'resultOf' => '0',
								'name'     => 'Email/set',
								'path'     => '/created/' . $email_create_id . '/id',
							),
							'identityId' => $identity_id,
						),
					),
				),
				'1',
			),
		);

		$responses = $client->request( $method_calls );

		if ( is_wp_error( $responses ) ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $responses->get_error_message() ) );
			return false;
		}

		// Check for errors in Email/set response.
		if ( isset( $responses[0][1]['notCreated'] ) && ! empty( $responses[0][1]['notCreated'] ) ) {
			$errors = $responses[0][1]['notCreated'];
			$first_error = reset( $errors );
			$error_msg = isset( $first_error['description'] ) ? $first_error['description'] : __( 'Failed to create email', 'bulwark-jmap-mail' );
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error_msg ) );
			return false;
		}

		// Check for errors in EmailSubmission/set response.
		if ( isset( $responses[1][1]['notCreated'] ) && ! empty( $responses[1][1]['notCreated'] ) ) {
			$errors = $responses[1][1]['notCreated'];
			$first_error = reset( $errors );
			$error_msg = isset( $first_error['description'] ) ? $first_error['description'] : __( 'Failed to submit email', 'bulwark-jmap-mail' );
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error_msg ) );
			return false;
		}

		// Check for JMAP-level errors (e.g. method-level error responses).
		foreach ( $responses as $resp ) {
			if ( isset( $resp[0] ) && $resp[0] === 'error' ) {
				$error_msg = isset( $resp[1]['description'] ) ? $resp[1]['description'] : __( 'JMAP error', 'bulwark-jmap-mail' );
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error_msg ) );
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse wp_mail headers into structured data.
	 */
	private function parse_headers( $headers ) {
		$result = array(
			'from_name'    => '',
			'from_email'   => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => 'text/plain',
		);

		if ( empty( $headers ) ) {
			return $result;
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( $headers as $header ) {
			if ( false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', trim( $header ), 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			switch ( $name ) {
				case 'from':
					if ( preg_match( '/(.*)<(.+)>/', $value, $matches ) ) {
						$result['from_name']  = trim( $matches[1], ' "' );
						$result['from_email'] = trim( $matches[2] );
					} else {
						$result['from_email'] = trim( $value );
					}
					break;
				case 'cc':
					$result['cc'][] = $value;
					break;
				case 'bcc':
					$result['bcc'][] = $value;
					break;
				case 'reply-to':
					$result['reply_to'][] = $value;
					break;
				case 'content-type':
					if ( false !== strpos( strtolower( $value ), 'text/html' ) ) {
						$result['content_type'] = 'text/html';
					}
					break;
			}
		}

		return $result;
	}

	/**
	 * Normalize an address field into an array of email strings.
	 */
	private function normalize_addresses( $addresses ) {
		if ( empty( $addresses ) ) {
			return array();
		}
		if ( is_string( $addresses ) ) {
			$addresses = array_map( 'trim', explode( ',', $addresses ) );
		}
		$result = array();
		foreach ( $addresses as $addr ) {
			$addr = trim( $addr );
			if ( ! empty( $addr ) ) {
				$result[] = $addr;
			}
		}
		return $result;
	}

	/**
	 * Convert address strings to JMAP EmailAddress objects.
	 */
	private function format_jmap_addresses( $addresses ) {
		$result = array();
		foreach ( $addresses as $addr ) {
			if ( preg_match( '/(.*)<(.+)>/', $addr, $matches ) ) {
				$result[] = array(
					'name'  => trim( $matches[1], ' "' ),
					'email' => trim( $matches[2] ),
				);
			} else {
				$result[] = array( 'email' => trim( $addr ) );
			}
		}
		return $result;
	}
}
