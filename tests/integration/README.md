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
