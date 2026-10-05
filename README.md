# Volance Detection (WordPress)

An **observe-only** WordPress connector for [Volance](https://volance.com).

It fetches a session, loads the reviewed collector **after visitor consent**,
relays the snapshot from PHP to the Volance scoring API, and logs the result in
wp-admin. It never blocks a submission and it fails open.

This plugin bundles **no Volance code**. It calls the public HTTPS API and loads
the collector from `https://app.volance.com`.

- Terminology: a score is an **estimate**, not proof of human presence.
- Terms: https://volance.com/terms
- Privacy: https://volance.com/privacy
- API reference: https://volance.com/docs/api
- Integration overview: https://volance.com/docs

## Status

Scaffold. This repo carries the metadata, the approved consent/privacy copy, and
a minimal skeleton. The connector itself (settings, form hooks, the PHP relay,
admin logging) is implemented by the maintainers. See `AGENTS.md` for the rules
and `docs/consent-and-privacy.md` for the exact copy to ship.

## Repository layout

```
volance-detection.php        Plugin bootstrap (header, settings, constants)
uninstall.php                Removes plugin options on uninstall
readme.txt                   WordPress.org readme
docs/consent-and-privacy.md  Approved consent + privacy + readme copy
assets/                      WordPress.org icons and banners
.github/workflows/ci.yml     PHP lint
```

## Development

```bash
php -l volance-detection.php   # lint
```

Requires PHP 8.0+ and WordPress 6.0+.

## License

GPL-2.0-or-later. See `LICENSE`. Copyright (C) 2026 Oops Games LLC.
