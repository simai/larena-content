# Code review feedback

Review scope: interface contracts, immutable value objects, descriptors,
fixtures, exact dependency closure and package gates.

Resolved during author review:

- Composer branch metadata was replaced with exact root-only package-source
  definitions so the development lock reproduces accepted revisions.
- The full transitive Larena closure, including Layout, is asserted.
- Dependency signature reflection checks compare deterministic sorted method
  sets rather than declaration order.
- Public and Search DTOs use exact-key payloads and omit typed values,
  attachment metadata from Search, draft pointers and security internals.
- `PublishedContentProjection` is no longer forgeable from raw lifecycle
  pointers or raw field arrays. Its factory requires the exact published
  `ContentItem`, immutable public `ContentRevision` and owner-supplied
  `StoragePublicProjection`, then verifies owner, schema, record id and record
  revision before projecting values.
- Public attachment metadata is created only from an immutable
  `ContentAttachmentReference` and a matching Filesystem inspection; the
  published projection verifies the exact item and revision.
- Draft, private, mismatched type/schema/record/owner/revision and stale
  attachment counterexamples fail closed.
- A single frozen `ContentRevision::MAX_ATTACHMENTS=100` limit guards revision
  metadata and public projections, with 100-pass/101-reject tests.
- Current published-head visibility/status/slug must match the immutable
  public revision, while an older published pointer may remain visible behind
  a newer private draft.
- Composer scripts use `@php` and nested lint uses `PHP_BINARY`, closing the
  pinned-toolchain reproducibility gap.
- Dataview declares read-only presentation ownership explicitly.

No runtime code is present, so this review does not accept transaction,
authorization, migration, HTTP, restart, rollback or database parity behavior.

Independent reverse-outcome review is recorded separately.
