# Volance Detection (WordPress)

An **observe-only** WordPress connector for [Volance](https://volance.com).

When a visitor allows it, a small script records how they interact with the
page. On a login, registration or comment submission, your server relays that to
the Volance scoring API and logs the estimated score in wp-admin. It never
blocks a submission and it fails open.

This plugin bundles **no Volance code**. It calls the HTTPS API and loads the
collector from `https://app.volance.com/trace.js` only after consent.

- A score is an **estimate**, not proof of human presence.
- Terms: https://volance.com/terms
- Privacy: https://volance.com/privacy
- API reference: https://volance.com/docs/api
- Integration overview: https://volance.com/docs

## Status

Connector implemented on `feat/connector` (settings, consent notice, relay,
activity log, usage line). Not yet released or submitted to WordPress.org.

## How it works

```
Visitor allows detection -> page script (collector) records timing/pointer data
Visitor submits a form   -> script copies a snapshot into a hidden field
Your server (after the response is sent):
   GET  /api/trace/session   (pk_)  -> sid + manifest, workspace fingerprint flag
   POST /api/trace/score     (sk_)  -> score, verdict, evidence flag
   -> one row in the activity log (no IP, user agent or form content)
```

- No snapshot (no consent): nothing is sent to Volance; the log shows "No
  browser evidence".
- Any error, timeout or 429: the form is unaffected; a log row and a settings
  notice explain what happened. A 429 pauses scoring for `Retry-After` seconds.
- If the Volance workspace has the fingerprint tier on, the plugin loads and
  sends nothing.
- A key pair where the secret key is the public key with a changed prefix (the
  old Volance format) is refused.

## Repository layout

```
volance-detection.php                  Bootstrap, activation, daily job
uninstall.php                          Removes options, log table, cron
includes/class-volance-detection-*.php Settings, evidence, client, scorer,
                                       forms, front end, admin, log, state
assets/js/volance-detection.js         Consent-first page module
assets/css/banner.css                  Consent notice styles
tests/run.php                          PHP tests (no WordPress needed)
tests/loader.test.mjs                  Page module tests (fake DOM)
readme.txt                             WordPress.org readme
docs/consent-and-privacy.md            Approved consent and privacy copy
```

## Development

```bash
php tests/run.php            # PHP logic tests
node tests/loader.test.mjs   # consent loader tests
php -l volance-detection.php # lint
git config core.hooksPath githooks
```

Requires PHP 8.0+ and WordPress 6.0+.

## License

GPL-2.0-or-later. See `LICENSE`. Copyright (C) 2026 Oops Games LLC.
