# AGENTS.md — Volance Detection (WordPress plugin)

Guidance for agents and humans working in **this** repository. Read it first.

## What this is

A GPL-2.0-or-later WordPress plugin that relays **consented** form-submission
signals to the Volance API and logs the human-likeness score in wp-admin.

It is **observe-only**: it never blocks, never redirects, and fails open.

## Hard rules

- **Observe-only.** Do not add blocking, enforcement, redirects, or challenge UI.
- **Bundle no Volance code.** Load the collector from the versioned URL
  (`https://app.volance.com/trace.<hash>.js`, or `/trace.js`). Do not copy the
  engine, collector, or functions source into this plugin.
- **Consent first.** Collection starts only on affirmative consent. Nothing is
  collected from a page or field the owner has not enabled.
- **No content.** Never record keystroke characters, form values, clipboard
  contents, or page text. The API rejects content-bearing fields server-side too.
- **Secrets stay server-side.** The `sk_` secret key is used only in PHP. It must
  never reach browser code or the front end.
- **Honest copy.** No customer claims; never write "no behavioral tracking"; a
  score is an estimate, not proof of human presence.
- **WordPress.org guidelines:** escape and sanitize all input/output, use nonces,
  internationalize strings, and disclose the external service in `readme.txt`.

## Scope

The connector calls only:

- `GET /api/trace/session` (public `pk_`) to get a session manifest, and
- `POST /api/trace/score` (secret `sk_`, from the server) to score a submission.

It does not use the portal, the paygate, or Web Bot Auth features.

## References

- `docs/consent-and-privacy.md` — approved consent, privacy, and readme copy.
- https://volance.com/docs and https://volance.com/docs/api — the product docs.
