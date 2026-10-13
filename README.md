# Volance Detection (WordPress)

The WordPress connector for [Volance](https://volance.com). It tells humans,
AI agents and bots apart on your forms, pages and REST endpoints, using the
Volance scoring API, and can log, flag or block what looks automated.

This plugin bundles **no Volance code**. It calls the HTTPS API and loads the
collector from `https://app.volance.com/trace.js`.

- A score is an **estimate**, not proof of human presence.
- Terms: https://volance.com/terms
- Privacy: https://volance.com/privacy
- API reference: https://volance.com/docs/api
- Integration overview: https://volance.com/docs

## What it does (v2.0.0)

| Area | Behaviour |
|------|-----------|
| Forms | WordPress login, registration, lost password, comments; WooCommerce login, registration, checkout; Contact Form 7; WPForms; Gravity Forms |
| Modes | Per form: `observe` (log only), `flag` (hold comments; elsewhere logs the flag and fires `volance_detection_flagged`), `block` (refuse) |
| Paths | Any page or REST route, one rule per line (`block /wp-json/wp/v2/comments`, `flag /pricing/*`); administrators exempt |
| Dashboard | Overview stats, Activity log with evidence per entry, CSV export |
| Policy | Workspace enforcement toggle and the fixed allow / block thresholds (`/api/trace/config`). Flag and block act only while workspace enforcement is on |
| Fingerprint tier | Follows the workspace setting like the install snippet: off by default; when on, the script also sends canvas-blank, font count and WebGL renderer |
| Agents | Web Bot Auth signature headers forwarded so declared agents are recognised |
| Plan | Plan and monthly usage (`/api/trace/usage`) |
| Safety | Fails open on any API error, timeout or 429 |

Portal-only features (sessions, audit log, members, billing) stay in the
[Volance portal](https://app.volance.com); the Help tab links to them. The 402
paygate for agent monetization is planned for 2.1.

> Volance is a different product from TapHuman. Its privacy and enforcement
> rules are its own; see `AGENTS.md`.

## How it works

```
Page with a protected form or path -> collector records timing/pointer data
                                      (after consent, or always, per setting)
Visitor submits                    -> script copies a snapshot into a hidden field
Your server:
   GET  /api/trace/session   (pk_)  -> sid + manifest
   POST /api/trace/score     (sk_)  -> score, verdict, recommended action
   decision = mode x recommended action -> allow | flag | block
   -> one row in the activity log (no IP, user agent or form content)
```

- Observe mode scores after the response is sent; flag and block score first
  (3 second limit, no retries) and then fail open.
- No snapshot: the submission is allowed and logged as "No browser evidence",
  unless the site chose to score those too.
- Any error, timeout or 429: the form is unaffected; a log row and a settings
  notice explain what happened. A 429 pauses scoring for `Retry-After` seconds.
- A key pair where the secret key is the public key with a changed prefix (the
  old Volance format) is refused.

## Hooks

Actions: `volance_detection_decision`, `volance_detection_flagged`,
`volance_detection_blocked`.
Filters: `volance_detection_block_message`, `volance_detection_skip_user`,
`volance_detection_load_collector`, `volance_detection_visitor_ip`.

## Repository layout

```
volance-detection.php                  Bootstrap, activation, daily job
uninstall.php                          Removes options, log table, cron
includes/class-volance-detection-*.php Settings, evidence, client, scorer, decision,
                                       forms, paths, front end, log, stats, export, state
includes/admin/                        Overview, Activity, Plan and Help tabs
assets/js/volance-detection.js         Consent-aware page module
assets/js/admin.js, assets/css/        Admin and consent notice assets
tests/run.php                          PHP tests (no WordPress needed)
tests/loader.test.mjs                  Page module tests (fake DOM)
tests/integration/                     Real-WordPress tests (see its README)
readme.txt                             WordPress.org readme
docs/consent-and-privacy.md            Consent and privacy copy
```

## Development

```bash
php tests/run.php            # PHP logic tests
node tests/loader.test.mjs   # page module tests
php -l volance-detection.php # lint
git config core.hooksPath githooks
```

Real-WordPress integration tests run in WordPress Playground; see
`tests/integration/README.md`.

Requires PHP 8.0+ and WordPress 6.0+.

## License

GPL-2.0-or-later. See `LICENSE`. Copyright (C) 2026 Oops Games LLC.
