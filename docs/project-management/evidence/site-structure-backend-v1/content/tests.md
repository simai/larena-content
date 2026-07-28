# Verification receipts

- `composer test`: PASS, 214 tests, 1777 assertions; four opt-in MySQL tests are skipped in the default SQLite run.
- `composer analyse`: PASS, PHPStan reports no errors.
- `composer lint`: PASS for package source, migrations, routes and tests.
- SQLite: authorization, immutable draft/review/publish/restore, anonymous published projection, direct redirect, canonical and route conflicts, SitePack import/replay and fresh-process restart pass.
- MySQL: opt-in disposable allowlisted databases pass the runtime and SitePack scenarios, including structure, SEO, redirect, clean import, replay and restart; cleanup proves test schemas are removed.
- restart: both SQLite and MySQL reopen a fresh runtime and retain the published structure, SEO metadata, redirect and Content UUIDs.
- rollback: empty site-structure migration rollback/reapply is reproducible; a populated rollback fails closed before the first drop.
- SitePack: a clean destination restores three structure revisions and one redirect, and a second import reports zero creates.
- Exact dependency closure: `larena/access` is pinned to `28cae5ad9bb5b401dc95a4d79becaaeb8d8ea5ad`; all other Larena revisions remain unchanged.
- Sealed rollback/reactivation: pending Root composition after the Content commit is published.
- `production_ready=false`
- `frontend_complete=false`
- `all_42_packages_ready=false`
