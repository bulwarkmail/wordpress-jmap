# Bulwark JMAP Mail

A WordPress plugin that replaces the default PHP mail function with the modern [JMAP protocol](https://jmap.io/) (RFC 8620/8621) for sending emails.

## Features

- Sends all WordPress emails via your JMAP server
- Automatic JMAP session discovery via `.well-known/jmap`
- HTML and plain text email support with automatic plain-text fallback
- File attachment support via JMAP blob upload
- CC, BCC, and Reply-To header support
- Identity auto-detection from JMAP server
- Separate test recipient for admin test emails
- Connection test and test email from the admin panel
- Compatible with any RFC 8620/8621 compliant JMAP server (Stalwart, Cyrus, etc.)

## How It Works

1. Hooks into the WordPress `pre_wp_mail` filter to intercept outgoing email
2. Discovers the JMAP session at `{server}/.well-known/jmap`
3. Resolves the sender's identity and the Sent mailbox from the server
4. Creates the email via `Email/set`, then submits it via `EmailSubmission/set` using the returned email id

## Requirements

- A JMAP-compatible mail server with `urn:ietf:params:jmap:submission` capability
- PHP 7.4 or later
- WordPress 5.8 or later

Tested with [Stalwart Mail Server](https://stalw.art/). Should work with Cyrus IMAP and other RFC 8620/8621 compliant servers.

## Installation

1. Upload the `bulwark-jmap-mail` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → JMAP Mail**
4. Enter your JMAP server URL, username, and password
5. Enable the plugin and save
6. Optionally set a dedicated **Test Recipient** address for admin test emails
7. Use the **Test Connection** button to verify your setup, then **Send Test Email** to confirm delivery

## FAQ

**What is JMAP?**
JMAP (JSON Meta Application Protocol) is a modern open standard for email access and submission defined in RFC 8620 and RFC 8621. It replaces IMAP and SMTP with a single JSON-over-HTTP protocol.

**Does this replace SMTP plugins?**
Yes. Instead of configuring SMTP credentials, you configure your JMAP server and all WordPress emails are sent through JMAP's `EmailSubmission` mechanism.

**Is my password stored securely?**
The password is stored in the WordPress options table. For additional security, consider defining credentials via `wp-config.php` constants or a secrets manager.

## Changelog

### 1.0.0

- Initial release
- JMAP session discovery and authentication
- Email sending via `Email/set` + `EmailSubmission/set`
- Attachment support via blob upload
- Admin settings page with connection tester
- HTML and plain text email support

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
