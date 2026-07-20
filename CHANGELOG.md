# Changelog

## Unreleased

### Added

- Interface-first contracts and immutable value objects for all seven frozen
  Content Platform v1 features.
- Access, Audit, Search and Dataview descriptors with explicit ownership and
  privacy boundaries.
- Exact dependency-revision compatibility tests and reusable acceptance
  fixtures.
- Package quality, scope and evidence gates for the interface checkpoint.
- Closed draft `api.yaml` contract and package-owned handlers/read model for
  20 Content administration operations.
- Immutable Content type-version list/read plus strictly additive schema
  preview and atomic create orchestration through Storage owner policy.
- Current-schema restoration for historical item revisions, including
  cross-schema restore without implicit republication.
- Lazy Rest handler and Search reindex-source registration that does not retain
  scoped database services across container lifetimes.
- Bounded type-head response for schema-version creation without a
  post-mutation Content/Storage reread.
- Focused API schema, serialization, schema migration, audit, lifetime and
  rollback regression coverage.
- Strict one-to-one Content type/Storage schema versions: every accepted
  version appends an optional field, while same-field projection or metadata
  candidates return `409 type_version_no_change`.
- Fail-closed detailed reads for persisted Content/Storage identity or hash
  drift and invalid persisted enums, projections and attachment manifests.
- Attachment-denial Audit redaction for non-canonical logical-file paths.

### Not included

- Destructive schema evolution, field rename/reorder/type changes, required
  field additions or a separately durable Content migration-plan ledger.
- Blob upload/delete or a visual frontend/admin interface.
- Production, release, update-server or all-packages readiness.
