=== Volance Detection ===
Contributors: thisissohard002
Tags: bot detection, ai agents, form security, spam, login protection
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tells humans, AI agents and bots apart on your logins, registrations, comments, checkout and contact forms, and any page or REST endpoint you choose, using the Volance service.

== Description ==

Volance Detection connects your site to the Volance service. A small script records how a visitor interacts with the page. When they submit a protected form, your server passes that to Volance, which returns a human-likeness score and a recommended action (allow, flag or block). The plugin can then simply log the result, hold the submission for review, or refuse it. You choose per form.

**What you can do**

* **Protect forms.** WordPress login, registration, lost password and comments; WooCommerce login, registration and checkout; Contact Form 7; WPForms; Gravity Forms.
* **Choose a mode per form.** *Observe* (score and log only), *Flag* (hold comments for moderation, mark spam, never lock anyone out) or *Block* (refuse the submission with a clear message).
* **Protect any page or REST endpoint.** List paths such as `/wp-json/wp/v2/comments` or `/pricing/*`, each with its own mode. Administrators are exempt.
* **See what is happening.** An Overview dashboard with totals, trends and top forms, an Activity log with the evidence behind each score, and a CSV export.
* **Set your thresholds.** Read and change your Volance workspace's allow and block thresholds without leaving WordPress.
* **Recognise signed agents.** Requests from AI agents that identify themselves with Web Bot Auth signature headers are forwarded to Volance so they can be classified as declared agents.
* **Watch your usage.** See your plan and how many scores you have used this month.
* **Fails open.** If Volance is slow, down or over quota, your forms work exactly as before.

A score is an **estimate**. It is not proof that someone is human or a bot.

Your secret key stays on your server. It is never printed on a page or sent to a browser.

You need a Volance account. Create one at https://app.volance.com.

**Consent.** You decide how visitors are informed. Use the built-in Allow / Decline notice, wire up your own consent tool, or choose "Always on" if your own privacy notice covers the script. When the built-in notice is used, nothing is collected and the Volance script is not even downloaded until the visitor clicks Allow, and a browser that sends Global Privacy Control is treated as declined.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/volance-detection`, or install it from the Plugins screen.
2. Activate it.
3. Go to **Settings > Volance Detection > Setup**. Paste your public key (`pk_...`) and secret key (`sk_...`) from the Volance portal and click **Test connection**.
4. Choose a mode for each form (start with *Observe*), pick how consent is handled, and tick **Turn on**.
5. Watch the **Overview** and **Activity** tabs, then move forms to *Flag* or *Block* when you are happy with the results.

If you prefer, define `VOLANCE_DETECTION_SECRET_KEY` in `wp-config.php` instead of saving the secret key in the database.

== Frequently Asked Questions ==

= Does this plugin block bots? =

Only if you tell it to. Every form starts in *Observe* mode, which never changes what a visitor sees. Switch a form to *Flag* or *Block* to act on Volance's recommendation. In *Flag* mode a blocked-looking submission is held (comments) or marked as spam rather than refused.

= What happens if a real person gets blocked? =

Blocking follows the thresholds set in your Volance workspace; change them on the Setup tab. A blocked visitor sees a short message and can try again. Administrators are never blocked on protected paths. Start in Observe or Flag mode and review the Activity log before using Block.

= What if a visitor's browser sends no evidence? =

By default the submission is allowed and logged as "No browser evidence". You can instead have Volance score it anyway.

= What exactly is sent to Volance? =

For a submission to a protected form or path: interaction timing, pointer movement, wheel and scroll timing, key press timing (never which keys), coarse browser and device details, the visitor's IP address, and request headers (User-Agent, Accept-Language, Sec-CH-UA, Sec-Fetch, the order of the request headers, proxy-related headers, whether a cookie was present as a yes/no flag, and any Web Bot Auth signature headers). For protected paths the page path is also sent. Volance stores the IP address only as a one-way hash. See the External services section.

= What is never collected? =

The characters a visitor types, form values, clipboard contents, page text and cookie values. The plugin and the Volance service both reject content-bearing fields. The plugin does not forward the canvas, font or WebGL fingerprint tier.

= How do I ask visitors for consent? =

Use the built-in Allow / Decline notice, choose "Use my own consent tool" and call `window.volanceDetection.setConsent(true)` when a visitor agrees (and `false` if they decline or withdraw), or choose "Always on". You can also dispatch a `volance-detection:consent` event on `document` with `{ detail: { granted: true } }`.

= Can developers hook in? =

Yes. Actions: `volance_detection_decision`, `volance_detection_flagged`, `volance_detection_blocked`. Filters: `volance_detection_block_message`, `volance_detection_skip_user`, `volance_detection_load_collector`, `volance_detection_visitor_ip`.

= How long is data kept? =

This plugin's log is deleted after 30 days. Volance deletes raw scores and sessions after 30 days; see its privacy policy.

= I use a proxy or CDN. Will the visitor IP be right? =

By default the plugin sends `REMOTE_ADDR`. If your site sits behind a trusted proxy, return the real address from the `volance_detection_visitor_ip` filter.

= Will it slow my forms down? =

In *Observe* mode scoring runs after the response has been sent on servers that support it (PHP-FPM and LiteSpeed). In *Flag* and *Block* modes the submission waits for the score, up to a 3 second limit per call with no retries, and then fails open.

= What happens when I uninstall? =

The plugin removes its settings, log table and scheduled task. Data already stored by Volance is managed in the Volance portal.

= Which features are not in the plugin yet? =

Session lists, the audit log, team members, billing and 402 pay-per-request agent monetization are available in the Volance portal at https://app.volance.com. The Help tab links to them.

== External services ==

This plugin connects to the Volance service, operated by Oops Games LLC, to estimate whether a form submission came from a human, an agent or a bot.

**1. Volance script (visitor's browser).** On pages with a protected form or path, and once consent is given (or immediately if you chose "Always on"), the visitor's browser downloads and runs https://app.volance.com/trace.js. It makes no network requests of its own. Downloading it reveals the visitor's IP address and browser details to Volance's servers, as any web request does.

**2. Volance scoring API (your server).** When a protected form or path receives a submission, your server sends Volance a request to https://app.volance.com/api/trace/session and then https://app.volance.com/api/trace/score, authenticated with your workspace keys. The request contains:

* interaction timing, pointer movement, wheel and scroll timing, key press timing (never which keys) and coarse browser and device descriptors recorded by the script;
* the visitor's IP address, which Volance stores only as a one-way hash;
* the visitor's request headers: User-Agent, Accept-Language, Sec-CH-UA, Sec-Fetch-Site / Sec-Fetch-Mode, the order of the header names, proxy-related headers, whether a cookie was present (yes or no, never its value) and, for signed agents, the Web Bot Auth signature headers;
* for a protected path, the page path (without query string).

It never contains typed characters, form values, clipboard contents, page text or cookie values.

**3. Volance account calls (administrators).** The Test connection button, the plan and usage tab, and the workspace policy panel call https://app.volance.com/api/trace/config (read and, when you save thresholds, update), /session and /usage from your server with your keys. A scheduled task also checks your workspace settings twice a day. Token verification (https://app.volance.com/api/trace/verify-token) is available to developers through the plugin's code only.

Volance Terms: https://volance.com/terms
Volance Privacy Policy: https://volance.com/privacy

== Screenshots ==

1. Overview: totals, trends and top forms.
2. Activity log with the evidence behind each score.
3. Setup: keys, a mode for each form, protected paths and workspace thresholds.

== Changelog ==

= 2.0.0 =
* New: Flag and Block modes per form, in addition to Observe.
* New: protect WooCommerce login, registration and checkout; Contact Form 7; WPForms; Gravity Forms; and lost password.
* New: protected paths for any page or REST endpoint, with a mode per path.
* New: Overview dashboard, evidence for each activity entry, CSV export and a Help tab.
* New: edit the workspace allow and block thresholds from WordPress.
* New: forward Web Bot Auth signed-agent headers so declared agents are recognised.
* New: option to score submissions that arrive without browser evidence, and an "Always on" consent mode.
* New: hooks `volance_detection_decision`, `_flagged`, `_blocked` and filters `volance_detection_block_message`, `_skip_user`, `_load_collector`.
* Changed: the plugin no longer stops when the workspace fingerprint tier is on.

= 0.1.0 =
* First release: settings, consent notice, observe-only scoring of login, registration and comment forms, activity log, plan and usage line.

== Upgrade Notice ==

= 2.0.0 =
Adds optional blocking, more forms and protected paths. Existing forms stay in Observe mode, so nothing changes until you choose otherwise.
