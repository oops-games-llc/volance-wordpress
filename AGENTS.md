# AGENTS.md — Volance Detection (WordPress plugin)

Guidance for agents and humans working in **this** repository. Read it first.

## What this is

A GPL-2.0-or-later WordPress plugin that relays **consented** form-submission
signals to the Volance API, logs the human-likeness score in wp-admin, and (as
Volance features arrive) lets the site owner act on it.

The shipped v0.1.x code is observe-only: it logs and never changes a
submission. That describes **what v0.1.x does, not a limit on the product**.
See "Observe vs. act" below.

## Volance is not TapHuman

This repo is **Volance**, a behavioral detection product. The restrictions that
are written for **TapHuman CAPTCHA** (the CAPTCHA captures no user information,
no-behavioral-tracking wording, observe-only wording copied from its
docs) **do not apply here**. Do not import TapHuman rules, copy, or claims into
this plugin. Volance's own boundaries are the ones in this file and in
`docs/consent-and-privacy.md`.

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

## Hard rules

- **Observe vs. act.** Acting on a score (holding a comment, flagging a user or
  order, returning 402/403 to an agent) is **allowed**. It is a feature, not a
  violation. When you add it, it must be:
  - **Opt-in and off by default**, and off for every existing site on upgrade.
    Shadow mode (log what would happen, act on nothing) comes before any action.
  - **Fail open.** If Volance is slow, down, or over quota, the visitor's request
    behaves as it did before the plugin existed.
  - **Scored results only.** Never act on `no_evidence`, `error`, `limited`, or
    `skipped`. A visitor who declined consent or sent Global Privacy Control is
    never penalized for it.
  - **Soft before hard.** Start with moderation queues, flags, and notes. A hard
    block of a login or checkout needs its own review.
  - **Honest copy, same PR.** `readme.txt`, the FAQ, settings text, the admin
    banner, and the consent copy must describe the behavior the code actually
    has. "Never blocks" may only appear while that is true. Observe-only is the
    default mode, not a promise about every mode.
- **Bundle no Volance code.** Load the collector from `https://app.volance.com/trace.js`.
  Do not pin the content-hashed `trace.<hash>.js`: an unknown or retired hash
  returns an HTML page with a 200 status and a one-year cache header. Do not copy
  the engine, collector, or functions source into this plugin.
- **Consent first.** Collection starts only on affirmative consent. Nothing is
  collected from a page or field the owner has not enabled.
- **No content.** Never record keystroke characters, form values, clipboard
  contents, or page text. The API rejects content-bearing fields server-side too.
- **Secrets stay server-side.** The `sk_` secret key is used only in PHP. It must
  never reach browser code or the front end.
- **Reject legacy keys.** A key pair where `sk_` body equals `pk_` body is the old
  derivable format and must be refused with a "rotate your keys" message.
- **Visitor description.** The visitor's IP and headers go in `requestSignals`
  (never as the relay's own headers). Do not send `page` or cookies.
- **Honest copy.** No customer claims; never write "no behavioral tracking"; a
  score is an estimate, not proof of human presence. The visitor IP is *sent* to
  Volance; Volance *stores* only a hash.
- **WordPress.org guidelines:** escape and sanitize all input/output, use nonces,
  internationalize strings, and disclose the external service in `readme.txt`.

## Tests

```
php tests/run.php          # PHP logic (no WordPress needed)
node tests/loader.test.mjs # consent loader with a fake DOM
```

## Scope

**Shipped in v0.1.x**, the connector calls only:

- `GET /api/trace/session` (public `pk_`) to get a session manifest and the
  workspace's `collect.fingerprints` flag,
- `POST /api/trace/score` (secret `sk_`, from the server) to score a submission,
- `GET /api/trace/config` (secret `sk_`) for the Test connection button, and
- `GET /api/trace/usage` (secret `sk_`) for the plan and usage line.

**Allowed to add** (each needs the readme "External services" section updated in
the same PR): `POST /api/trace/config` (always send an explicit `enabled`
boolean), `POST /api/trace/verify-token`, the agent routes
(`/api/trace/agent/decide`, `verify`, `settlement`, `iou`, `config`), and
Web Bot Auth `disclosureHeaders` forwarding.

**Still out of bounds:** the Firebase-authenticated `/api/portal/*` routes (the
plugin holds only `pk_`/`sk_`; link out to the portal instead), and editing the
monetization payout wallet from WordPress.

## References

- `docs/consent-and-privacy.md` — approved consent, privacy, and readme copy.
- https://volance.com/docs and https://volance.com/docs/api — the product docs.
