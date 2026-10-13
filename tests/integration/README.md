# Integration test (real WordPress)

Boots WordPress in WordPress Playground with this plugin mounted, mocks the Volance
API through `pre_http_request`, and exercises the real login, registration and
comment hooks, the log table and every admin tab.

```
npx @wp-playground/cli@latest run-blueprint ^
  --blueprint=tests/integration/blueprint.json ^
  --mount-dir-before-install "<path to this repo>" /wordpress/wp-content/plugins/volance-detection ^
  --mount-dir-before-install "<path to this repo>\tests\integration" /tests
```

`run-blueprint` does not print PHP output, so results are written to
`tests/integration/result.txt` (git-ignored). A passing run ends with
`INTEGRATION: N passed, 0 failed`.

Third-party form plugins are covered by a second script. It installs WooCommerce,
Contact Form 7 and WPForms Lite from wordpress.org (PHP 8.3 is required by CF7) and stubs
Gravity Forms:

```n--blueprint=tests/integration/forms-plugins.blueprint.json   (result: tests/integration/result-forms.txt)
```n