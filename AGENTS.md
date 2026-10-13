# AGENTS.md — Volance Detection (WordPress plugin)

Guidance for agents and humans working in **this** repository. Read it first.

## What this is

A GPL-2.0-or-later WordPress plugin that brings Volance's detection tools into
WordPress: it scores logins, registrations, password resets, comments and
WooCommerce / Contact Form 7 submissions with the Volance API, shows the results
and evidence in wp-admin, and (per form, owner's choice) logs, flags or blocks
suspicious submissions. The plugin should behave like the Volance website
(`app.volance.com` portal and `volance.com` docs), limited only by what the
key-authenticated API (`/api/trace/*`) exposes.

## Product boundary (important)

Volance is **not** TapHuman. The TapHuman CAPTCHA rules (no behavioral signals,
no user information, no fingerprinting, observe-only) do **not** apply here.
Do not import them. Volance may block, flag, and use the signals the website
documents. If a Volance-specific constraint is needed, it comes from
`docs/product/` in the Volance monorepo or from the owner, not from TapHuman.

## Repo boundaries

This repository is the WordPress plugin **only**. It is separate from the Volance
monorepo (`oops-games-llc/volance`), which owns `functions/`, `packages/`,
`apps/`, `mockups/`, the Firebase/pnpm config, and the `docs/product`,
`docs/setup`, `docs/website`, `docs/team` docs. **Never add monorepo code here**,
and never push this repo to the monorepo remote.

`githooks/pre-push` enforces both. Enable it once per clone:

```
git config core.hooksPath githooks
```

## Engineering rules

- **Secrets stay server-side.** The `sk_` secret key is used only in PHP. It must
  never reach browser code, the front end, logs or error messages.
- **Reject legacy keys.** A key pair where `sk_` body equals `pk_` body is the old
  derivable format and must be refused with a "rotate your keys" message.
- **Fail open.** If Volance is slow, down, rate limited or over quota, the
  visitor's request is allowed and the problem is logged. A 3 second timeout per
  call, no retries.
- **Bundle no Volance code.** Load the collector from `https://app.volance.com/trace.js`.
  Do not pin the content-hashed `trace.<hash>.js`: an unknown or retired hash
  returns an HTML page with a 200 status and a one-year cache header. Do not copy
  the engine, collector, or functions source into this plugin.
- **Visitor description.** The visitor's IP and headers go in `requestSignals`
  (never as the relay's own headers); signed-agent headers go in `disclosureHeaders`.
- **Honest copy.** No customer claims. A score is an estimate, not proof of human
  presence. The visitor IP is *sent* to Volance; Volance *stores* only a hash.
- **Local log.** The activity log must not store IP addresses, raw user agents,
  form values or the trust token.
- **WordPress.org guidelines:** escape and sanitize all input/output, use nonces,
  internationalize strings, and disclose every external service call in
  `readme.txt` (External services section).
- **Per-form modes.** Every protected form has a mode: `off`, `observe`, `flag` or
  `block`. `observe` scores after the response is sent; `flag`/`block` score
  before the request completes. Default for a newly enabled form is `observe`.

## Tests

```
php tests/run.php          # PHP logic (no WordPress needed)
node tests/loader.test.mjs # consent loader with a fake DOM
```

PHP is not on PATH on the dev machine; Local by Flywheel ships one at
`%APPDATA%\Local\lightning-services\php-*\bin\win64\php.exe`.

## API surface

Key-authenticated routes the plugin may call (see `docs/product/contracts.md`
and `agent-monetization.md` in the monorepo):

- `GET /api/trace/session` (public `pk_`), `POST /api/trace/score` (`sk_`),
- `GET | POST /api/trace/config` (`sk_`): workspace policy and thresholds,
- `GET /api/trace/usage` (`sk_`), `POST /api/trace/verify-token` (`sk_`),
- `POST /api/trace/agent/decide`, `/agent/verify`, `/agent/settlement`,
  `GET | POST /agent/config`: agent monetization (402 paygate), planned for 2.1.

Portal features (sessions, audit, members, billing) sit behind Firebase login and
are not reachable with `pk_`/`sk_`. The plugin links to the portal for those until
the website adds key-authenticated endpoints (separate follow-up).

## References

- `docs/consent-and-privacy.md` — consent, privacy, and readme copy (to be refreshed for 2.0).
- https://volance.com/docs and https://volance.com/docs/api — the product docs.
