# Larena Content

Generic CMS content types, item identity, immutable revisions, route
reservations, publication state, logical-file attachment associations and
exact published safe projections for Larena.

The current accepted development checkpoint is interface-first. It contains
stable PHP contracts, immutable value objects, Access/Audit/Search descriptors,
Dataview ownership boundaries, exact dependency compatibility checks and
non-persistent acceptance fixtures for all seven Content Platform v1 features.

Canonical specifications are in `simai/larena-specs` at
`790ba64b651dc47d416417262a385c26f097bbc7`.

This checkpoint intentionally contains no migrations, repositories, service
provider, routes, controllers or runtime mutations. It does not claim
production readiness, frontend readiness or readiness of all Larena packages.

## Quality gate

Use the pinned PHP and Composer toolchain recorded in
`.larena/launch-context.json`, then run:

```bash
composer install --no-interaction --prefer-dist
composer run quality:gate
```

The evidence bundle is under
`docs/project-management/evidence/data-content/batch-1/content-interface-first/`.
