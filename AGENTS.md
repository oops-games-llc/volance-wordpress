# AGENTS.md — Volance Detection (WordPress plugin)

Guidance for agents and humans working in **this** repository. Read it first.

## What this is

A GPL-2.0-or-later WordPress plugin that relays **consented** form-submission
signals to the Volance API and logs the human-likeness score in wp-admin.

It is **observe-only**: it never blocks, never redirects, and fails open.

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

- **Observe-only.** Do not add blocking, enforcement, redirects, or challenge UI.
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

The connector calls only:

- `GET /api/trace/session` (public `pk_`) to get a session manifest and the
  workspace's `collect.fingerprints` flag,
- `POST /api/trace/score` (secret `sk_`, from the server) to score a submission,
- `GET /api/trace/config` (secret `sk_`) for the Test connection button, and
- `GET /api/trace/usage` (secret `sk_`) for the plan and usage line.

It does not use the portal, the paygate, or Web Bot Auth features.

## References

- `docs/consent-and-privacy.md` — approved consent, privacy, and readme copy.
- https://volance.com/docs and https://volance.com/docs/api — the product docs.
