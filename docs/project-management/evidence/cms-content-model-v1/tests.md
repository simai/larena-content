# Tests

Runtime toolchain: PHP 8.3.31, PHPUnit 12.5.31.

- SQLite package suite: PASS — 197 tests, 1413 assertions.
- CMS v1 focused scenario: PASS — 3 tests, 20 assertions.
- MySQL package suite with disposable owned database and guarded cleanup: PASS — 3 tests, 37 assertions.
- restart persistence: PASS — 1 test, 6 assertions in a fresh process composition.
- migration rollback/reapply: PASS — 7 tests, 40 assertions.
- PHPStan: PASS, no errors.
- PHP syntax lint: PASS.
- Composer strict validation: PASS.
- Package contract validator: PASS.

Clean install, predecessor update, sealed rollback/reactivation, and browser/runtime acceptance are integration-stage receipts and remain to be captured against the new root distribution.

Bounded nonclaims: `production_ready=false`, `frontend_complete=false`, `all_42_packages_ready=false`.
