# Consent, privacy, and readme copy (approved)

This is the copy the plugin must ship. It reflects Volance's published privacy
position: on-site only, no cross-site identifiers, hashed IPs, 30-day raw
retention, opt-in. Do **not** write "no behavioral tracking" and do **not** claim
a score proves anything.

## Consent (opt-in banner)

> **Bot detection (optional).** We use an on-site script to estimate whether a
> form submission comes from a human, an agent, or a bot. It records interaction
> timing, pointer movement, and coarse browser/device details **on this site
> only** — never the characters you type, form values, or clipboard — and sends
> them to our server, which relays them to Volance for scoring. Your IP is stored
> only as a one-way hash. Nothing is collected unless you allow it. — **Allow** /
> **Decline**
> `[Read our privacy policy]` · `[How Volance handles data]`

Collection is off by default and starts only on affirmative consent
(`{ consent: true }`).

## Suggested privacy-policy text

Add this with `wp_add_privacy_policy_content()` so it appears in the WordPress
privacy guide:

> We use Volance to estimate whether a form submission comes from a human, an
> agent, or a bot. Collection happens only after you opt in. The script records
> interaction timing, pointer movement, and coarse device details on this site
> only — never the characters you type, form values, or clipboard contents. Your
> IP address is stored only as a one-way hash, and raw scores are deleted after
> 30 days. No cross-site identifiers are used. Data is processed by Volance (by
> Oops Games LLC); see https://volance.com/privacy.

## Facts (keep consistent with Volance)

- **Collected (after consent):** interaction timing, pointer movement, wheel and
  scroll timing, coarse device/browser descriptors, and a salted one-way hash of
  the IP.
- **Never collected:** keystroke characters, form values, clipboard content, page
  text, or page URLs.
- **Fingerprinting:** off. The plugin never enables the fingerprint tier; if the
  workspace has it on, the plugin refuses to load the collector.
- **Retention:** raw scores/sessions purged after 30 days; longer-lived data is
  aggregate; sessions are deletable in the Volance portal.
- **Processors:** Google Firebase (`us-central1`), Cloudflare (DNS/CDN), Resend
  (email), Stripe (billing).
- **Roles:** the integrating site controls its visitors' data; Volance acts as a
  processor.
- **Stable URLs:** terms https://volance.com/terms · privacy
  https://volance.com/privacy.
