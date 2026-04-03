<?php
/**
 * Plugin Name: Bulwark JMAP Mail
 * Plugin URI: https://github.com/bulwark/bulwark-jmap-mail
 * Description: Replaces WordPress default mail sending with JMAP (RFC 8620/8621) protocol support. Send emails via any JMAP-compatible server.
 * Version: 1.0.0
 * Author: Bulwark
 * Author URI: https://bulwark.dev
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: bulwark-jmap-mail
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BULWARK_JMAP_VERSION', '1.0.0' );
define( 'BULWARK_JMAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BULWARK_JMAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BULWARK_JMAP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BULWARK_JMAP_PLUGIN_DIR . 'includes/class-jmap-client.php';
require_once BULWARK_JMAP_PLUGIN_DIR . 'includes/class-jmap-mailer.php';
require_once BULWARK_JMAP_PLUGIN_DIR . 'includes/class-admin-settings.php';

/**
 * Initialize the plugin.
 */
function bulwark_jmap_init() {
	if ( is_admin() ) {
		new Bulwark_JMAP_Admin_Settings();
	}

	$options = get_option( 'bulwark_jmap_settings', array() );
	if ( ! empty( $options['enabled'] ) && ! empty( $options['server_url'] ) ) {
		new Bulwark_JMAP_Mailer( $options );
	}
}
add_action( 'plugins_loaded', 'bulwark_jmap_init' );

/**
 * Add settings link on plugins page.
 */
function bulwark_jmap_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=bulwark-jmap-mail' ) ) . '">'
		. esc_html__( 'Settings', 'bulwark-jmap-mail' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . BULWARK_JMAP_PLUGIN_BASENAME, 'bulwark_jmap_action_links' );

/**
 * Set default options on activation.
 */
function bulwark_jmap_activate() {
	if ( false === get_option( 'bulwark_jmap_settings' ) ) {
		add_option( 'bulwark_jmap_settings', array(
			'enabled'    => 0,
			'server_url' => '',
			'username'   => '',
			'password'   => '',
			'from_name'  => get_bloginfo( 'name' ),
			'from_email' => get_bloginfo( 'admin_email' ),
		) );
	}
}
register_activation_hook( __FILE__, 'bulwark_jmap_activate' );
