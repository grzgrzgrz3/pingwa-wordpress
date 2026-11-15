=== pingwa – order and form alerts ===
Contributors: pingwa
Tags: whatsapp, woocommerce, contact form 7, notifications, forms
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WhatsApp alerts for new orders and form submissions, to you and your team. Official WhatsApp API: no Meta setup, your own number never at risk.

== Description ==

WhatsApp alerts from any tool, to you and your team. This plugin brings them to WordPress: every new WooCommerce order and every form submission becomes one WhatsApp message on your phone.

pingwa runs on the official WhatsApp API: no Meta setup, no templates, and your own number is never at risk. There is no WhatsApp Business account to create and no phone to keep linked. You text **join wp** to pingwa once, make a hook URL, and paste it here.

= What sends a message =

Each trigger has its own switch, on by default while its plugin is active:

* **WooCommerce new order** – one message per order, when it first reaches processing, on-hold or completed (like WooCommerce's own "New order" e-mail): order number, total, currency, status, items, the customer's name, the payment method and a link to the order in your admin. Classic and block checkout, HPOS order tables included.
* **Contact Form 7**
* **WPForms**
* **Elementor Pro forms**
* **Gravity Forms**

A form message carries the form title and each field's label and value. Passwords, file uploads, hidden fields, captchas and honeypots are never sent, and long values are cut.

= What it never does =

* It never sends the customer's e-mail address, phone number or address.
* It never slows down or breaks a checkout or a form: the message is sent at the very end of the request, without waiting for an answer (3 second cap), and any error is swallowed (and logged only while WP_DEBUG is on).
* It never stores anything but the hook URL. No API key, no account login.
* It never messages your customers. pingwa messages only people who joined it from their own WhatsApp: you, and your teammates when your account has a team.

= Getting a hook URL =

1. Text **join wp** to pingwa on WhatsApp (Settings → pingwa has the button and a QR code). Your API key comes straight back, and your phone is the one that gets the messages.
2. In the pingwa panel open Hooks → + new hook, set Sender to "Custom — any other sender", name it and click create hook. Copy the URL: it is shown once. It looks like `https://pingwa.grzgrzgrz3.ovh/h/hk_…`.
3. Paste it in Settings → pingwa, save, and press **Send test**.

== External services ==

This plugin connects to pingwa (https://pingwa.grzgrzgrz3.ovh), the service that turns a request to your hook URL into a WhatsApp message to you.

* **What is sent, and when:** an HTTPS POST of a small JSON body to the hook URL you saved, (1) when a WooCommerce order is placed, with the order number, total, currency, status, items (name, quantity, total), the billing first and last name, the payment method title, the admin link to the order and the site name; (2) when a form in Contact Form 7, WPForms, Elementor Pro or Gravity Forms is submitted, with the form title, the site name and the submitted field labels and values (never passwords, uploads, hidden fields, captchas or honeypots); (3) when you press **Send test**, with a test text and the site name. Nothing is sent before you save a hook URL, and nothing for a trigger you switch off.
* pingwa's terms of service: https://pingwa.grzgrzgrz3.ovh/terms
* pingwa's privacy policy: https://pingwa.grzgrzgrz3.ovh/privacy

The "Send join wp on WhatsApp" button and the QR code on the settings page are a link to WhatsApp (wa.me); they are followed only when you click or scan them. The QR code is an image shipped with the plugin, not fetched from any service.

== Frequently Asked Questions ==

= Does pingwa message my customers? =

No. pingwa messages only people who joined it from their own WhatsApp: you, and teammates when your account has a team. Use WooCommerce's own e-mails for customers.

= Which sender should the hook have? =

"Custom — any other sender": every message is then the text this plugin writes, for orders and forms alike. A hook made with the sender "WooCommerce: new order" or "Form: new response" works too; pingwa's template then picks the fields.

= I changed an order's status. Why no new message? =

The plugin sends one message per order, at the same moment WooCommerce sends you its own "New order" e-mail: when the order first reaches processing, on-hold or completed. A pending (unpaid) or failed order sends nothing until it is paid; later status changes and edits send nothing.

= Is the hook URL a secret? =

Yes. Anyone with it can send you messages, so it works like a password. The settings page shows only its last four characters once saved. If it leaks, revoke the hook in the pingwa panel and paste a new one.

= What does it cost? =

Every message counts against your pingwa plan's monthly quota, like any pingwa message. The plans are on https://pingwa.grzgrzgrz3.ovh.

== Screenshots ==

1. Settings → pingwa: the join link and QR code, the hook URL, the triggers and Send test.

== Changelog ==

= 0.1.0 =
* First release: Contact Form 7, WPForms, Elementor Pro forms, Gravity Forms and WooCommerce new order to WhatsApp through a pingwa hook URL.

== Upgrade Notice ==

= 0.1.0 =
First release.
