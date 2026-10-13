# Consent, privacy, and readme copy

This is the copy the plugin ships. It reflects Volance's published privacy
position: on-site only, no cross-site identifiers, a one-way IP hash stored by
Volance, and 30-day raw retention. Do **not** claim a score proves anything.

Consent handling is the site owner's choice (Setup tab):

- **Built-in notice** (default): collection is off until the visitor clicks Allow;
  a browser that sends Global Privacy Control is treated as declined.
- **My own consent tool**: the site calls `window.volanceDetection.setConsent(true|false)`.
- **Always on**: the script loads on protected pages without a prompt. The site
  owner is responsible for covering this in their own privacy notice.

## Consent (built-in banner)

> **Bot detection (optional).** We use an on-site script to estimate whether a
> form submission comes from a human, an agent, or a bot. It records interaction
> timing, pointer movement, and coarse browser and device details on this site
> only. It never records the characters you type, form values, or your
> clipboard. This data, your IP address and your browser headers are sent to our
> server, which passes them to Volance for scoring. Volance stores your IP only
> as a one-way hash. Nothing is collected unless you allow it. — **Allow** /
> **Decline**
> `[Read our privacy policy]` · `[How Volance handles data]`

## Suggested privacy-policy text

Added with `wp_add_privacy_policy_content()` so it appears in the WordPress
privacy guide:

> We use Volance to estimate whether a form submission on this site comes from a
> human, an agent, or a bot. Where we ask first, collection starts only after you
> opt in. The script records interaction timing, pointer movement, and coarse
> browser and device details on this site only. It never records the characters
> you type, form values, or clipboard contents. When you submit a form, your IP
> address and browser headers are sent with that timing data to Volance, which
> stores your IP address only as a one-way hash. Raw scores are deleted after 30
> days. No cross-site identifiers are used. Volance is operated by Oops Games LLC;
> see https://volance.com/privacy.

## Facts (keep consistent with Volance)

- **Sent to Volance (on a protected submission):** interaction timing, pointer
  movement, wheel and scroll timing, key press timing (not which keys), coarse
  device/browser descriptors, the visitor's IP address, and the request headers
  User-Agent, Accept-Language, Sec-CH-UA, Sec-Fetch-Site/Mode, header name order,
  proxy-related headers, a yes/no flag for whether a cookie was present, and any
  Web Bot Auth signature headers. For protected paths, the page path (no query
  string) is also sent.
- **Stored by Volance:** the IP only as a salted one-way hash; raw scores and
  sessions for 30 days.
- **Never collected or sent:** keystroke characters, form values, clipboard
  content, page text, cookie values.
- **Fingerprint tier (canvas, fonts, WebGL):** the plugin does not forward these
  fields.
- **Retention:** raw scores/sessions purged after 30 days; longer-lived data is
  aggregate; sessions are deletable in the Volance portal. The plugin's own
  activity log is also purged after 30 days and holds no IP, browser details or
  form content.
- **Enforcement:** forms start in Observe mode. Flag and Block act on Volance's
  recommended action; any API error fails open.
- **Hosting and processors:** Volance runs on Google Firebase (`us-central1`)
  behind Cloudflare. It also uses Resend (email) and Stripe (billing) for account
  services. See https://volance.com/privacy for the authoritative list.
- **Roles:** the integrating site controls its visitors' data; Volance acts as a
  processor. A data-processing addendum is available from Volance on request.
- **Stable URLs:** terms https://volance.com/terms · privacy
  https://volance.com/privacy.
