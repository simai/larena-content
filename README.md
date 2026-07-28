# Larena Content

Generic CMS content types, item identity, immutable revisions, route
reservations, publication state, logical-file attachment associations and
exact published safe projections for Larena.

The current development checkpoint is a bounded implementation candidate built
on the accepted guarded runtime. It implements six Content-owned tables,
Storage-backed immutable revisions, Access and connection-bound Audit
integration, Filesystem inspection, public Search projections,
presentation-only Dataview rows and one anonymous read-only JSON route.

Product packages may delegate Search projection for an explicitly registered
Content type through `ContentSearchProjectionDelegationRegistry`. Content still
owns mutation, immutable publication state and the exact safe public
projection; the product adapter owns only its locator/text projection. The
generic Content Search source excludes delegated types so one published item
cannot produce both generic and product documents. Product adapters can read
an exact published item through `PublishedContentItemReader`; unpublished,
private, stale-route or inconsistent records remain indistinguishable as not
public.

The package also declares a closed draft administrator API under
`/api/v1/admin/content`. Its 25 operations cover content types, immutable type
versions, items, revisions, publication, logical-file attachment bindings and
CMS SitePack export, verification, dry-run and transactional import.
The API is loaded and protected by `larena/rest`; this package owns only its
registered handlers and Content read model.

Type evolution is intentionally narrow. A preview or create request must
submit the complete candidate definition, preserve every existing field
exactly and append only optional fields. Creating a version atomically asks
Storage to migrate all current record heads, appends matching draft Content
revisions and advances type and item heads through compare-and-swap. Existing
published revisions remain public until an explicit later publish. Restoring
an older revision always writes through the current schema and remains draft.

The SitePack adapter produces deterministic, integrity-checked `.sitepack`
archives using the shared SitePack format. It preserves Content UUIDs, schema
versions, revisions, publication state, relations and portable logical files.
Repeated exact imports are no-ops; malformed, incompatible or conflicting
packages fail closed. Physical paths, credentials and file bytes never enter
the portable descriptor or Audit payload.

Canonical specifications at `7e637fc4` and exact accepted dependency
revisions are recorded by `.larena/spec-ref.json`,
`.larena/launch-context.json` and `composer.lock`.

This candidate remains inside the accepted package boundary. Dataview does not
own Content records, Search remains a rebuildable public index, Rest remains
the only HTTP/API runtime, and public reads do not create a synthetic Access
actor. It does not claim production readiness, frontend readiness,
release/update-server readiness or readiness of all Larena packages.

Developer details are in
[`docs/developer/content-model-administration-api-v1.md`](docs/developer/content-model-administration-api-v1.md).

## Quality gate

Use the pinned PHP and Composer toolchain recorded in
`.larena/launch-context.json`, then run:

```bash
composer install --no-interaction --prefer-dist
composer run quality:gate
```

Current implementation evidence is under
`docs/project-management/evidence/cms-sitepack-portability-v1/content/`.
The previous accepted guarded-runtime proof remains separately preserved under
`docs/project-management/evidence/data-content/batch-2/content-guarded-runtime/`.
