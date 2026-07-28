# Verification receipts

- `composer test`: PASS, 215 tests, 1781 assertions; four opt-in MySQL tests are skipped in the default SQLite run.
- `composer analyse`: PASS, PHPStan reports no errors.
- `composer lint`: PASS for package source, migrations, routes and tests.
- SQLite: authorization, immutable draft/review/publish/restore, anonymous published projection, direct redirect, canonical and route conflicts, SitePack import/replay and fresh-process restart pass.
- MySQL: opt-in disposable allowlisted databases pass the runtime and SitePack scenarios, including structure, SEO, redirect, clean import, replay and restart; cleanup proves test schemas are removed.
- restart: both SQLite and MySQL reopen a fresh runtime and retain the published structure, SEO metadata, redirect and Content UUIDs.
- rollback: empty site-structure migration rollback/reapply is reproducible; a populated rollback fails closed before the first drop.
- SitePack: a clean destination restores three structure revisions and one redirect, and a second import reports zero creates.
- Redirect restore regression: restoring a previously published slug may reclaim its own managed redirect source, removes the now-obsolete owned redirect, and creates exactly one reverse redirect without a chain or cycle. A foreign redirect source still fails closed.
- Exact dependency closure: `larena/access` is pinned to `28cae5ad9bb5b401dc95a4d79becaaeb8d8ea5ad`; all other Larena revisions remain unchanged.
- Exact package revision: `f7896834f9090713da06ead15b73b2993f99bb09`.
- Sealed rollback/reactivation: Root `f95296c576deae7fb3165b60e6d166db95191d94` activates, rolls back and reactivates with the accepted state hash unchanged.
- `production_ready=false`
- `frontend_complete=false`
- `all_42_packages_ready=false`
