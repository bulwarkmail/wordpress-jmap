<?php
/**
 * JMAP Client - Handles session discovery and API requests.
 *
 * @package Bulwark_JMAP_Mail
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulwark_JMAP_Client {

	private $server_url;
	private $username;
	private $password;
	private $auth_header;
	private $api_url;
	private $account_id;
	private $upload_url;
	private $session;

	public function __construct( $server_url, $username, $password ) {
		$this->server_url = rtrim( $server_url, '/' );
		$this->username   = $username;
		$this->password   = $password;
		$this->auth_header = 'Basic ' . base64_encode( $username . ':' . $password );
	}

	/**
	 * Discover the JMAP session from .well-known/jmap.
	 *
	 * @return true|WP_Error
	 */
	public function discover_session() {
		$session_url = $this->server_url . '/.well-known/jmap';
		$response    = $this->request_session_document( $session_url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error(
				'jmap_session_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'JMAP session discovery failed with HTTP %d', 'bulwark-jmap-mail' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$this->session = json_decode( $body, true );

		if ( ! is_array( $this->session ) ) {
			return new WP_Error(
				'jmap_session_invalid_json',
				__( 'JMAP session response was not valid JSON', 'bulwark-jmap-mail' )
			);
		}

		if ( empty( $this->session['apiUrl'] ) ) {
			return new WP_Error(
				'jmap_session_invalid',
				__( 'JMAP session response missing apiUrl', 'bulwark-jmap-mail' )
			);
		}

		$this->api_url    = $this->session['apiUrl'];
		$this->upload_url = isset( $this->session['uploadUrl'] ) ? $this->session['uploadUrl'] : null;

		// Resolve the primary mail account.
		if ( ! empty( $this->session['primaryAccounts']['urn:ietf:params:jmap:mail'] ) ) {
			$this->account_id = $this->session['primaryAccounts']['urn:ietf:params:jmap:mail'];
		} else {
			return new WP_Error(
				'jmap_no_mail_account',
				__( 'No primary mail account found in JMAP session', 'bulwark-jmap-mail' )
			);
		}

		return true;
	}

	/**
	 * Request the JMAP session document, following redirects explicitly.
	 *
	 * Some servers redirect /.well-known/jmap to the actual session endpoint.
	 * Handle that here so auth headers are preserved consistently.
	 *
	 * @param string $session_url Discovery or session URL.
	 * @param int    $redirects   Remaining redirects to follow.
	 * @return array|WP_Error
	 */
	private function request_session_document( $session_url, $redirects = 3 ) {
		$response = wp_remote_get( $session_url, array(
			'headers'     => array(
				'Authorization' => $this->auth_header,
				'Accept'        => 'application/json',
			),
			'timeout'     => 30,
			'redirection' => 0,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			if ( $redirects < 1 ) {
				return new WP_Error(
					'jmap_session_redirect_limit',
					__( 'JMAP session discovery exceeded the redirect limit', 'bulwark-jmap-mail' )
				);
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				return new WP_Error(
					'jmap_session_redirect_missing',
					__( 'JMAP session discovery redirect did not include a Location header', 'bulwark-jmap-mail' )
				);
			}

			return $this->request_session_document(
				$this->resolve_redirect_url( $session_url, $location ),
				$redirects - 1
			);
		}

		return $response;
	}

	/**
	 * Resolve a redirect location against the request URL.
	 *
	 * @param string $request_url Original request URL.
	 * @param string $location    Redirect location header value.
	 * @return string
	 */
	private function resolve_redirect_url( $request_url, $location ) {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		$request_parts = wp_parse_url( $request_url );
		if ( empty( $request_parts['scheme'] ) || empty( $request_parts['host'] ) ) {
			return $location;
		}

		$origin = $request_parts['scheme'] . '://' . $request_parts['host'];
		if ( ! empty( $request_parts['port'] ) ) {
			$origin .= ':' . $request_parts['port'];
		}

		if ( 0 === strpos( $location, '//' ) ) {
			return $request_parts['scheme'] . ':' . $location;
		}

		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}

		$path = isset( $request_parts['path'] ) ? $request_parts['path'] : '/';
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );

		return $origin . $dir . ltrim( $location, '/' );
	}

	/**
	 * Make a JMAP API request.
	 *
	 * @param array  $method_calls Array of method call tuples.
	 * @param array  $using        Capability URNs.
	 * @return array|WP_Error The methodResponses array or error.
	 */
	public function request( $method_calls, $using = null ) {
		if ( empty( $this->api_url ) ) {
			$result = $this->discover_session();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( null === $using ) {
			$using = array(
				'urn:ietf:params:jmap:core',
				'urn:ietf:params:jmap:mail',
				'urn:ietf:params:jmap:submission',
			);
		}

		$payload = array(
			'using'       => $using,
			'methodCalls' => $method_calls,
		);

		$response = wp_remote_post( $this->api_url, array(
			'headers' => array(
				'Authorization' => $this->auth_header,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error(
				'jmap_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'JMAP API request failed with HTTP %d', 'bulwark-jmap-mail' ),
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['methodResponses'] ) ) {
			return new WP_Error(
				'jmap_response_invalid',
				__( 'JMAP response missing methodResponses', 'bulwark-jmap-mail' )
			);
		}

		return $body['methodResponses'];
	}

	/**
	 * Upload a blob (attachment) to the JMAP server.
	 *
	 * @param string $data     Binary file data.
	 * @param string $type     MIME type.
	 * @return string|WP_Error The blobId or error.
	 */
	public function upload_blob( $data, $type ) {
		if ( empty( $this->upload_url ) ) {
			$result = $this->discover_session();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( empty( $this->upload_url ) ) {
			return new WP_Error(
				'jmap_no_upload',
				__( 'JMAP server does not provide an upload URL', 'bulwark-jmap-mail' )
			);
		}

		// Replace template variables in upload URL per RFC 8620.
		$url = str_replace( '{accountId}', urlencode( $this->account_id ), $this->upload_url );

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => $this->auth_header,
				'Content-Type'  => $type,
			),
			'body'    => $data,
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'jmap_upload_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'JMAP blob upload failed with HTTP %d', 'bulwark-jmap-mail' ),
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['blobId'] ) ) {
			return new WP_Error(
				'jmap_upload_invalid',
				__( 'JMAP upload response missing blobId', 'bulwark-jmap-mail' )
			);
		}

		return $body['blobId'];
	}

	/**
	 * Resolve the sender's JMAP Identity ID.
	 *
	 * @param string $from_email The from email to match.
	 * @return string|WP_Error Identity ID or error.
	 */
	public function get_identity_id( $from_email ) {
		$responses = $this->request( array(
			array( 'Identity/get', array( 'accountId' => $this->account_id ), '0' ),
		) );

		if ( is_wp_error( $responses ) ) {
			return $responses;
		}

		if ( empty( $responses[0][1]['list'] ) ) {
			return new WP_Error(
				'jmap_no_identities',
				__( 'No sending identities found on the JMAP server', 'bulwark-jmap-mail' )
			);
		}

		$identities = $responses[0][1]['list'];

		// Try to match the from_email.
		foreach ( $identities as $identity ) {
			if ( isset( $identity['email'] ) && strtolower( $identity['email'] ) === strtolower( $from_email ) ) {
				return $identity['id'];
			}
		}

		// Fallback to first identity.
		return $identities[0]['id'];
	}

	public function get_account_id() {
		return $this->account_id;
	}

	/**
	 * Check if the server supports email submission.
	 *
	 * @return bool
	 */
	public function supports_submission() {
		if ( empty( $this->session ) ) {
			$this->discover_session();
		}
		return isset( $this->session['capabilities']['urn:ietf:params:jmap:submission'] );
	}

	/**
	 * Get the first mailbox ID matching a given role (e.g. 'sent', 'drafts').
	 *
	 * @param string $role The JMAP mailbox role.
	 * @return string|WP_Error Mailbox ID or error.
	 */
	public function get_mailbox_id_by_role( $role ) {
		$responses = $this->request( array(
			array(
				'Mailbox/query',
				array(
					'accountId' => $this->account_id,
					'filter'    => array( 'role' => $role ),
				),
				'0',
			),
		) );

		if ( is_wp_error( $responses ) ) {
			return $responses;
		}

		if ( ! empty( $responses[0][1]['ids'][0] ) ) {
			return $responses[0][1]['ids'][0];
		}

		return new WP_Error(
			'jmap_no_mailbox',
			sprintf(
				/* translators: %s: mailbox role */
				__( 'No JMAP mailbox found with role: %s', 'bulwark-jmap-mail' ),
				$role
			)
		);
	}

	/**
	 * Get the session data (for diagnostics).
	 *
	 * @return array|null
	 */
	public function get_session() {
		return $this->session;
	}
}
