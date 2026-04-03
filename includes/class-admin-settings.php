<?php
/**
 * Admin Settings Page for Bulwark JMAP Mail.
 *
 * @package Bulwark_JMAP_Mail
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulwark_JMAP_Admin_Settings {

	private $option_name = 'bulwark_jmap_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_bulwark_jmap_test', array( $this, 'ajax_test_connection' ) );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Bulwark JMAP Mail', 'bulwark-jmap-mail' ),
			__( 'JMAP Mail', 'bulwark-jmap-mail' ),
			'manage_options',
			'bulwark-jmap-mail',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'bulwark_jmap_group', $this->option_name, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		add_settings_section(
			'bulwark_jmap_main',
			__( 'JMAP Server Configuration', 'bulwark-jmap-mail' ),
			array( $this, 'render_section_info' ),
			'bulwark-jmap-mail'
		);

		$fields = array(
			'enabled'    => __( 'Enable JMAP Mail', 'bulwark-jmap-mail' ),
			'server_url' => __( 'JMAP Server URL', 'bulwark-jmap-mail' ),
			'username'   => __( 'Username', 'bulwark-jmap-mail' ),
			'password'   => __( 'Password', 'bulwark-jmap-mail' ),
			'from_name'  => __( 'From Name', 'bulwark-jmap-mail' ),
			'from_email' => __( 'From Email', 'bulwark-jmap-mail' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'bulwark_jmap_' . $key,
				$label,
				array( $this, 'render_field_' . $key ),
				'bulwark-jmap-mail',
				'bulwark_jmap_main'
			);
		}
	}

	public function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled']    = ! empty( $input['enabled'] ) ? 1 : 0;
		$sanitized['server_url'] = esc_url_raw( trim( $input['server_url'] ?? '' ) );
		$sanitized['username']   = sanitize_text_field( $input['username'] ?? '' );
		$sanitized['from_name']  = sanitize_text_field( $input['from_name'] ?? '' );
		$sanitized['from_email'] = sanitize_email( $input['from_email'] ?? '' );

		// Only update password if a new one was provided.
		if ( ! empty( $input['password'] ) ) {
			$sanitized['password'] = $input['password'];
		} else {
			$old = get_option( $this->option_name, array() );
			$sanitized['password'] = $old['password'] ?? '';
		}

		return $sanitized;
	}

	public function render_section_info() {
		echo '<p>' . esc_html__( 'Configure your JMAP server connection. The server must support RFC 8620 (JMAP Core) and RFC 8621 (JMAP Mail).', 'bulwark-jmap-mail' ) . '</p>';
	}

	private function get_option( $key, $default = '' ) {
		$options = get_option( $this->option_name, array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	public function render_field_enabled() {
		$checked = $this->get_option( 'enabled' );
		echo '<label><input type="checkbox" name="' . esc_attr( $this->option_name ) . '[enabled]" value="1" ' . checked( 1, $checked, false ) . ' /> '
			. esc_html__( 'Route all WordPress emails through JMAP', 'bulwark-jmap-mail' ) . '</label>';
	}

	public function render_field_server_url() {
		$value = $this->get_option( 'server_url' );
		echo '<input type="url" class="regular-text" name="' . esc_attr( $this->option_name ) . '[server_url]" value="' . esc_attr( $value ) . '" placeholder="https://mail.example.com" />';
		echo '<p class="description">' . esc_html__( 'Base URL of your JMAP server. Session will be discovered at /.well-known/jmap', 'bulwark-jmap-mail' ) . '</p>';
	}

	public function render_field_username() {
		$value = $this->get_option( 'username' );
		echo '<input type="text" class="regular-text" name="' . esc_attr( $this->option_name ) . '[username]" value="' . esc_attr( $value ) . '" autocomplete="off" />';
	}

	public function render_field_password() {
		echo '<input type="password" class="regular-text" name="' . esc_attr( $this->option_name ) . '[password]" value="" autocomplete="new-password" placeholder="' . esc_attr__( 'Enter new password or leave blank to keep current', 'bulwark-jmap-mail' ) . '" />';
	}

	public function render_field_from_name() {
		$value = $this->get_option( 'from_name', get_bloginfo( 'name' ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( $this->option_name ) . '[from_name]" value="' . esc_attr( $value ) . '" />';
	}

	public function render_field_from_email() {
		$value = $this->get_option( 'from_email', get_bloginfo( 'admin_email' ) );
		echo '<input type="email" class="regular-text" name="' . esc_attr( $this->option_name ) . '[from_email]" value="' . esc_attr( $value ) . '" />';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'bulwark_jmap_group' );
				do_settings_sections( 'bulwark-jmap-mail' );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Test Connection', 'bulwark-jmap-mail' ); ?></h2>
			<p><?php esc_html_e( 'Save your settings first, then test the connection to your JMAP server.', 'bulwark-jmap-mail' ); ?></p>
			<button type="button" id="bulwark-jmap-test" class="button button-secondary">
				<?php esc_html_e( 'Test JMAP Connection', 'bulwark-jmap-mail' ); ?>
			</button>
			<button type="button" id="bulwark-jmap-test-email" class="button button-secondary">
				<?php esc_html_e( 'Send Test Email', 'bulwark-jmap-mail' ); ?>
			</button>
			<div id="bulwark-jmap-test-result" style="margin-top: 10px;"></div>
			<script>
			(function(){
				var labels = {
					account: '<?php echo esc_js( __( 'Account', 'bulwark-jmap-mail' ) ); ?>',
					capabilities: '<?php echo esc_js( __( 'Capabilities', 'bulwark-jmap-mail' ) ); ?>',
					warning: '<?php echo esc_js( __( 'Warning', 'bulwark-jmap-mail' ) ); ?>',
					warnings: '<?php echo esc_js( __( 'Warnings', 'bulwark-jmap-mail' ) ); ?>'
				};

				document.getElementById('bulwark-jmap-test').addEventListener('click', function(){
					runTest('connection');
				});
				document.getElementById('bulwark-jmap-test-email').addEventListener('click', function(){
					runTest('email');
				});

				function appendParagraph(container, text, strong) {
					var p = document.createElement('p');
					if (strong) {
						var strongEl = document.createElement('strong');
						strongEl.textContent = text;
						p.appendChild(strongEl);
					} else {
						p.textContent = text;
					}
					container.appendChild(p);
				}

				function appendLabeledValue(container, label, value, useCode) {
					var p = document.createElement('p');
					var strong = document.createElement('strong');
					strong.textContent = label + ': ';
					p.appendChild(strong);
					if (useCode) {
						var code = document.createElement('code');
						code.textContent = value;
						p.appendChild(code);
					} else {
						p.appendChild(document.createTextNode(value));
					}
					container.appendChild(p);
				}

				function appendList(container, items, useCode) {
					var list = document.createElement('ul');
					list.style.margin = '8px 0 0 20px';
					items.forEach(function(item){
						var li = document.createElement('li');
						if (useCode) {
							var code = document.createElement('code');
							code.textContent = item;
							li.appendChild(code);
						} else {
							li.textContent = item;
						}
						list.appendChild(li);
					});
					container.appendChild(list);
				}

				function renderResult(resultDiv, success, data) {
					var cls = success ? 'notice-success' : 'notice-error';
					var wrap = document.createElement('div');
					wrap.className = 'notice ' + cls + ' inline';
					var content = document.createElement('div');

					if (data && typeof data === 'object' && !Array.isArray(data)) {
						if (data.message) {
							appendParagraph(content, data.message, true);
						}
						if (data.account) {
							appendLabeledValue(content, labels.account, data.account, true);
						}
						if (data.capabilities && data.capabilities.length) {
							var details = document.createElement('details');
							details.open = true;
							var summary = document.createElement('summary');
							summary.textContent = labels.capabilities + ' (' + data.capabilities.length + ')';
							details.appendChild(summary);
							appendList(details, data.capabilities, true);
							content.appendChild(details);
						}
						if (data.warnings && data.warnings.length) {
							appendParagraph(content, data.warnings.length === 1 ? labels.warning : labels.warnings, true);
							appendList(content, data.warnings, false);
						}
					}

					if (!content.childNodes.length) {
						appendParagraph(content, data || '', false);
					}

					wrap.appendChild(content);
					resultDiv.innerHTML = '';
					resultDiv.appendChild(wrap);
				}

				function runTest(type) {
					var resultDiv = document.getElementById('bulwark-jmap-test-result');
					resultDiv.innerHTML = '<p><em><?php echo esc_js( __( 'Testing...', 'bulwark-jmap-mail' ) ); ?></em></p>';
					var data = new FormData();
					data.append('action', 'bulwark_jmap_test');
					data.append('test_type', type);
					data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'bulwark_jmap_test' ) ); ?>');
					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r){ return r.json(); })
						.then(function(r){
							renderResult(resultDiv, r.success, r.data);
						})
						.catch(function(e){
							renderResult(resultDiv, false, { message: e.message });
						});
				}
			})();
			</script>
		</div>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'bulwark_jmap_test', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Permission denied.', 'bulwark-jmap-mail' ),
			) );
		}

		$options = get_option( $this->option_name, array() );

		if ( empty( $options['server_url'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'Please configure and save the JMAP server URL, username, and password first.', 'bulwark-jmap-mail' ),
			) );
		}

		$client = new Bulwark_JMAP_Client(
			$options['server_url'],
			$options['username'],
			$options['password']
		);

		$result = $client->discover_session();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
			) );
		}

		$test_type = isset( $_POST['test_type'] ) ? sanitize_text_field( $_POST['test_type'] ) : 'connection';

		if ( 'email' === $test_type ) {
			$to = ! empty( $options['from_email'] ) ? $options['from_email'] : get_bloginfo( 'admin_email' );
			$mail_error = null;
			$error_handler = function( $error ) use ( &$mail_error ) {
				$mail_error = $error;
			};

			add_action( 'wp_mail_failed', $error_handler, 10, 1 );
			$sent = wp_mail(
				$to,
				__( 'Bulwark JMAP Mail - Test Email', 'bulwark-jmap-mail' ),
				__( 'This is a test email sent via Bulwark JMAP Mail plugin. If you received this, your JMAP configuration is working correctly.', 'bulwark-jmap-mail' )
			);
			remove_action( 'wp_mail_failed', $error_handler, 10 );

			if ( $sent ) {
				wp_send_json_success( array(
					'message' => __( 'Test email sent successfully!', 'bulwark-jmap-mail' ),
				) );
			} else {
				$message = __( 'Failed to send test email. Check your JMAP server logs.', 'bulwark-jmap-mail' );

				if ( $mail_error instanceof WP_Error && $mail_error->get_error_message() ) {
					$message = $mail_error->get_error_message();
				}

				wp_send_json_error( array(
					'message' => $message,
				) );
			}
		} else {
			$session = $client->get_session();
			$capabilities = array_keys( $session['capabilities'] ?? array() );
			$has_submission = $client->supports_submission();
			$warnings = array();

			if ( ! $has_submission ) {
				$warnings[] = __( 'Server does not advertise urn:ietf:params:jmap:submission capability. Email sending may not work.', 'bulwark-jmap-mail' );
			}

			$identity_result = $client->get_identity_id( $options['from_email'] ?? '' );
			if ( is_wp_error( $identity_result ) ) {
				$warnings[] = $identity_result->get_error_message();
			}

			wp_send_json_success( array(
				'message'      => __( 'Connection successful!', 'bulwark-jmap-mail' ),
				'account'      => (string) $client->get_account_id(),
				'capabilities' => array_values( $capabilities ),
				'warnings'     => $warnings,
			) );
		}
	}
}
