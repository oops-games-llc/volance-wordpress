# Consent, privacy, and readme copy (approved)

This is the copy the plugin ships. It reflects Volance's published privacy
position: on-site only, no cross-site identifiers, a one-way IP hash stored by
Volance, 30-day raw retention, opt-in. Do **not** write "no behavioral tracking"
and do **not** claim a score proves anything.

> Correction (2026-10-06): earlier drafts said only a hash of the IP is *sent*.
> The plugin sends the visitor's IP address to Volance (needed for network
> checks); **Volance stores only a one-way hash**. The plugin also sends the
> visitor's User-Agent, Accept-Language, Sec-CH-UA and Sec-Fetch headers. All
> copy below reflects that.

## Consent (opt-in banner)

> **Bot detection (optional).** We use an on-site script to estimate whether a
> form submission comes from a human, an agent, or a bot. It records interaction
> timing, pointer movement, and coarse browser and device details on this site
> only. It never records the characters you type, form values, or your
> clipboard. This data, your IP address and your browser headers are sent to our
> server, which passes them to Volance for scoring. Volance stores your IP only
> as a one-way hash. Nothing is collected unless you allow it. — **Allow** /
> **Decline**
> `[Read our privacy policy]` · `[How Volance handles data]`

Collection is off by default and starts only on affirmative consent
(`{ consent: true }`). A browser that sends Global Privacy Control is treated as
declined.

## Suggested privacy-policy text

Added with `wp_add_privacy_policy_content()` so it appears in the WordPress
privacy guide:

> We use Volance to estimate whether a form submission on this site comes from a
> human, an agent, or a bot. Collection happens only after you opt in. The script
> records interaction timing, pointer movement, and coarse browser and device
> details on this site only. It never records the characters you type, form
> values, or clipboard contents. When you submit a form, your IP address and
> browser headers are sent with that timing data to Volance, which stores your IP
> address only as a one-way hash. Raw scores are deleted after 30 days. No
> cross-site identifiers are used. Volance is operated by Oops Games LLC; see
> https://volance.com/privacy.

## Facts (keep consistent with Volance)

- **Sent to Volance (after consent, on submit):** interaction timing, pointer
  movement, wheel and scroll timing, key press timing (not which keys), coarse
  device/browser descriptors, the visitor's IP address, and the User-Agent,
  Accept-Language, Sec-CH-UA and Sec-Fetch-Site/Mode headers.
- **Stored by Volance:** the IP only as a salted one-way hash; raw scores and
  sessions for 30 days.
- **Never collected or sent:** keystroke characters, form values, clipboard
  content, page text, page URLs (the plugin omits the `page` field), cookies.
- **Fingerprinting:** off. The plugin never enables the fingerprint tier; if the
  workspace has it on, the plugin loads and sends nothing.
- **Retention:** raw scores/sessions purged after 30 days; longer-lived data is
  aggregate; sessions are deletable in the Volance portal. The plugin's own
  activity log is also purged after 30 days and holds no IP, browser details or
  form content.
- **Hosting and processors:** Volance runs on Google Firebase (`us-central1`)
  behind Cloudflare. It also uses Resend (email) and Stripe (billing) for account
  services. See https://volance.com/privacy for the authoritative list.
- **Roles:** the integrating site controls its visitors' data; Volance acts as a
  processor. A data-processing addendum is available from Volance on request.
- **Stable URLs:** terms https://volance.com/terms · privacy
  https://volance.com/privacy.
