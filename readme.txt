=== Bulwark JMAP Mail ===
Contributors: bulwark
Tags: jmap, email, mail, smtp-replacement, rfc8620, rfc8621
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Replaces WordPress default mail sending with JMAP (RFC 8620/8621) protocol support.

== Description ==

**Bulwark JMAP Mail** replaces the built-in WordPress PHP mail function with the modern JMAP protocol for sending emails. JMAP (JSON Meta Application Protocol) is an open standard (RFC 8620, RFC 8621) designed as a modern, efficient replacement for IMAP and SMTP.

= Features =

* Sends all WordPress emails via your JMAP server
* Automatic JMAP session discovery via `.well-known/jmap`
* HTML and plain text email support
* File attachment support via JMAP blob upload
* CC, BCC, and Reply-To header support
* Identity auto-detection from JMAP server
* Connection test and test email from admin panel
* Compatible with any RFC 8620/8621 compliant JMAP server (Stalwart, Cyrus, etc.)

= How It Works =

1. Hooks into WordPress `pre_wp_mail` filter to intercept outgoing email
2. Discovers JMAP session at `{server}/.well-known/jmap`
3. Creates the email via `Email/set` and submits via `EmailSubmission/set`
4. Uses JMAP back-references for atomic create+submit in a single request

= Requirements =

* A JMAP-compatible mail server with `urn:ietf:params:jmap:submission` capability
* PHP 7.4 or later
* WordPress 5.8 or later

== Installation ==

1. Upload the `bulwark-jmap-mail` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → JMAP Mail
4. Enter your JMAP server URL, username, and password
5. Enable the plugin and save
6. Use the Test Connection button to verify your setup

== Frequently Asked Questions ==

= What is JMAP? =
JMAP (JSON Meta Application Protocol) is a modern open standard for email access and submission,
defined in RFC 8620 and RFC 8621. It replaces IMAP and SMTP with a single JSON-over-HTTP protocol.

= Which JMAP servers are supported? =
Any server implementing RFC 8620 (JMAP Core), RFC 8621 (JMAP Mail), and RFC 8621 email submission.
Tested with Stalwart Mail Server. Should work with Cyrus IMAP and other compliant servers.

= Does this replace SMTP plugins? =
Yes. Instead of configuring SMTP, you configure your JMAP server credentials and all WordPress
emails are sent through JMAP's EmailSubmission mechanism.

= Is my password stored securely? =
The password is stored in the WordPress options table. For additional security, consider defining
credentials via `wp-config.php` constants or using a secrets manager.

== Changelog ==

= 1.0.0 =
* Initial release
* JMAP session discovery and authentication
* Email sending via Email/set + EmailSubmission/set
* Attachment support via blob upload
* Admin settings page with connection tester
* HTML and plain text email support
