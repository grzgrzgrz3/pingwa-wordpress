=== pingwa – order and form alerts ===
Contributors: pingwa
Tags: whatsapp, woocommerce, contact form 7, notifications, forms
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WhatsApp alerts for new orders and form messages, to you and your team. Official WhatsApp API: no Meta setup, your own number never at risk.

== Description ==

Get a WhatsApp message on your phone for every new WooCommerce order and every message sent through your contact forms. You, and your team if you have one, no longer have to watch your inbox.

pingwa is a messaging service that runs on the official WhatsApp API, so there is no Meta account to set up, no message templates to get approved, and your own WhatsApp number is never at risk. There is no WhatsApp Business app to install and no phone to keep linked.

**Setting up takes a few minutes.** You send the word **join wp** to pingwa in WhatsApp once, create a private link for your site (pingwa calls it a "hook URL"), and paste it into this plugin's settings page. Done: the next order or form message arrives on your phone.

= What sends a message =

Each trigger has its own switch, on by default while its plugin is active:

* **WooCommerce new order** – one message per order, when it first reaches processing, on-hold or completed (like WooCommerce's own "New order" e-mail): order number, total, currency, status, items, the customer's name, the payment method and a link to the order in your admin. Classic and block checkout, HPOS order tables included.
* **Contact Form 7** – every submission
* **WPForms** – every submission
* **Elementor Pro forms** – every submission
* **Gravity Forms** – every submission

A form message carries the form title and each field's label and value. Passwords, file and image uploads, hidden fields, captchas and honeypots are never sent, and long values are cut.

= Setting it up =

1. Text **join wp** to pingwa on WhatsApp. Settings → pingwa has a button and a QR code for it: scan the code with your phone, or tap the button. pingwa answers in the same chat, and your phone is the one that gets the messages.
2. Open the pingwa panel (Settings → pingwa links to it), then Hooks → + new hook, set Sender to "Custom — any other sender", give it a name and click create hook. Copy the link (the hook URL): it is shown only once. It looks like `https://pingwa.dev/h/hk_…`.
3. Paste it in Settings → pingwa, save, and press **Send test**. A test message arrives on your phone.

You need a pingwa account for this. It is made by the first step, and pingwa has a free plan (see the cost question below).

= What it never does =

* It never sends a WooCommerce customer's e-mail address, phone number or address. (A form message does carry what the visitor typed into the form's fields, including an e-mail field if your form has one, so you can answer them.)
* It never breaks a checkout or a form. The message is sent at the very end of the request: where the server supports it (PHP-FPM, LiteSpeed) that is after the visitor already has the page, so they never wait. On other servers (for example Apache with mod_php) the visitor's page waits for the send: usually a fraction of a second, at most 2 seconds per message when pingwa is slow or unreachable. Any error is swallowed (and logged only while WP_DEBUG is on).
* It never stores anything but the hook URL. No API key, no account login.
* It never messages your customers. pingwa messages only people who joined it from their own WhatsApp: you, and your teammates when your account has a team.

== External services ==

This plugin connects to pingwa (https://pingwa.dev), the service that turns a request to your hook URL into a WhatsApp message to you.

* **What is sent, and when:** an HTTPS POST of a small JSON body to the hook URL you saved, (1) when a WooCommerce order is placed, with the order number, total, currency, status, items (name, quantity, total), the billing first and last name, the payment method title, the admin link to the order and the site name; (2) when a form in Contact Form 7, WPForms, Elementor Pro or Gravity Forms is submitted, with the form title, the site name and the submitted field labels and values (never passwords, uploads, hidden fields, captchas or honeypots); (3) when you press **Send test**, with a test text and the site name. Nothing is sent before you save a hook URL, and nothing for a trigger you switch off.
* pingwa's terms of service: https://pingwa.dev/terms
* pingwa's privacy policy: https://pingwa.dev/privacy

The "Send “join wp” on WhatsApp" button and the QR code on the settings page are a link to https://pingwa.dev/join/wp, a pingwa page that forwards you to WhatsApp (wa.me) with "join wp" ready to send; it is followed only when you click or scan it, never by the plugin. The QR code is an image shipped with the plugin, not fetched from any service.

== Frequently Asked Questions ==

= Does pingwa message my customers? =

No. pingwa messages only people who joined it from their own WhatsApp: you, and teammates when your account has a team. Use WooCommerce's own e-mails for customers.

= Which sender should the hook have? =

"Custom — any other sender": every message is then the text this plugin writes, for orders and forms alike. A hook made with the sender "WooCommerce: new order" or "Form: new response" works too; pingwa's template then picks the fields.

= I changed an order's status. Why no new message? =

The plugin sends one message per order, at the same moment WooCommerce sends you its own "New order" e-mail: when the order first reaches processing, on-hold or completed. A pending (unpaid), failed or cancelled order sends nothing until it is paid; later status changes and edits send nothing.

= I imported 2,000 orders. Will my phone explode? =

No. Orders created more than 24 hours ago are never sent, an order restored from the trash is never sent, and one request sends at most 5 order messages: the rest arrive as one "…and N more new orders" message with a link to your orders. Developers can change both limits with the `pingwa_order_max_age` (seconds) and `pingwa_max_order_messages` filters.

= Is the hook URL a secret? =

Yes. Anyone with it can send you messages, so it works like a password. The settings page shows only its last four characters once saved. If it leaks, revoke the hook in the pingwa panel and paste a new one.

= What does it cost? =

The plugin is free. The messages it sends count against your pingwa plan's monthly quota, like any pingwa message. pingwa has a free plan, and paid plans with a higher quota; the plans and their current limits are on https://pingwa.dev.

= Do I need WooCommerce? =

No. Each trigger has its own switch and only works while its plugin is active: use it for your forms alone, for WooCommerce alone, or for both.

== Screenshots ==

1. Settings → pingwa: the join link and QR code, the hook URL, the triggers and Send test.
2. A new WooCommerce order as a WhatsApp message: number, total, items, the customer's name and a link to the order.
3. A form submission as a WhatsApp message: the form title and each field's label and value.
4. The one-time setup: scan the QR code or tap the button and WhatsApp opens with "join wp" ready to send.

== Changelog ==

= 0.1.0 =
* First release: Contact Form 7, WPForms, Elementor Pro forms, Gravity Forms and WooCommerce new order to WhatsApp through a pingwa hook URL.

== Upgrade Notice ==

= 0.1.0 =
First release.
