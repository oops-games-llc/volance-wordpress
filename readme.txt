=== Volance Detection ===
Contributors: oopsgames
Tags: bot detection, agents, form security, spam
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Relays consented form-submission signals to Volance for a human-likeness score,
and logs the result in your WordPress admin. Observe-only.

== Description ==

Volance Detection relays consented form-submission signals to the Volance
service for a human-likeness score, and logs the result in your WordPress admin.

This connector is **observe-only**: it never blocks a submission and it fails
open. Collection starts only after the visitor opts in. It records interaction
timing, pointer movement, and coarse device/browser details on your own site; it
never records keystroke characters, form values, clipboard content, or page text.

A Volance score is an **estimate**, not proof of human presence. You decide what
to do with it in your own application.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/volance-detection`, or install
   from the WordPress plugin screen.
2. Activate the plugin.
3. Open **Settings → Volance Detection** and paste your project's public `pk_` and
   secret `sk_` keys from the Volance portal.
4. Optionally enable the consent banner, or wire your existing consent tool to the
   `volance_detection_consent` filter.

== Frequently Asked Questions ==

= Does this plugin block bots? =

No. It is observe-only: it scores a submission and logs it, and never blocks.

= What data is collected? =

Only after a visitor opts in: interaction timing, pointer movement, wheel/scroll
timing, coarse device/browser descriptors, and a salted hash of the IP. Never
keystrokes, form values, clipboard, or page text.

= Does it use fingerprinting? =

No. The plugin never enables the fingerprint tier. If your Volance workspace has
it switched on, the plugin refuses to load the collector and tells you.

= What are the retention rules? =

Raw scores and sessions are purged after 30 days; longer-lived data is aggregate.
Sessions can be deleted in the Volance portal at any time.

= Which processors are involved? =

Google Firebase (us-central1), Cloudflare (DNS/CDN), Resend (email), Stripe
(billing).

== External services ==

This plugin connects to the Volance service to score a form submission, and only
after the visitor opts in.

* **Volance API**
  When a consented visitor submits a form, the signals collected on the page are
  sent to your own server and relayed to Volance
  (https://app.volance.com/api/trace/score) using your workspace's secret key.
  Volance returns a human-likeness score, which this plugin logs only.
  Data sent: interaction timing, pointer movement, wheel/scroll timing, coarse
  device/browser descriptors, and a one-way salted hash of the visitor's IP.
  Never sent: keystroke characters, form values, clipboard content, or page text.
  * Terms: https://volance.com/terms
  * Privacy: https://volance.com/privacy

* **Volance collector script**
  After consent, the page loads the reviewed collector from
  https://app.volance.com/trace.js (a content-addressed version is also available
  for pinning). The script makes no network calls itself.

== Screenshots ==

1. Settings: connect your project keys.
2. A logged result in the admin.

== Changelog ==

= 0.1.0 =
* Initial scaffold.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
