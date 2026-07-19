# Larena Content

Generic CMS content types, item identity, immutable revisions, route
reservations, publication state, logical-file attachment associations and
exact published safe projections for Larena.

The current development checkpoint is a bounded guarded runtime candidate. It
implements six Content-owned tables, Storage-backed immutable revisions,
Access and connection-bound Audit integration, Filesystem inspection, public
Search projections, presentation-only Dataview rows and one anonymous
read-only JSON route for all seven Content Platform v1 features.

Canonical specifications are in `simai/larena-specs` at
`2c3c3106a4afb98925dbf8192cfaadb57ca4d4a9`; the governing frozen
implementation base is `8a5a007513f972ab3d9b89f427e3bb9a0a68a482`.

This candidate remains inside the accepted package boundary. Dataview does not
own Content records, Search remains a rebuildable public index, and the route
does not create a synthetic Access actor. It does not claim production readiness,
frontend readiness, release/update-server readiness or readiness of all Larena
packages.

## Quality gate

Use the pinned PHP and Composer toolchain recorded in
`.larena/launch-context.json`, then run:

```bash
composer install --no-interaction --prefer-dist
composer run quality:gate
```

The evidence bundle is under
`docs/project-management/evidence/data-content/batch-2/content-guarded-runtime/`.
It is populated only from completed SQLite, MySQL, restart, rollback,
atomicity, concurrency and independent-review checks.
