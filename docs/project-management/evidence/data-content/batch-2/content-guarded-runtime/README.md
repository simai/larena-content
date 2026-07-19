# Content guarded-runtime evidence

Date: 2026-07-19

Package: `larena/content`

Specs launch reference:
`simai/larena-specs@2c3c3106a4afb98925dbf8192cfaadb57ca4d4a9`

Governing frozen Specs base:
`8a5a007513f972ab3d9b89f427e3bb9a0a68a482`

Package base: `11fbc66eb23523549f061f8210d3f195f614ce55`

Batch: `batch-2-content-guarded-runtime`

This bundle records executable package-level evidence for the guarded Content
Platform v1 runtime:

- exactly six Content-owned tables;
- arbitrary typed Content schemas backed by immutable Storage versions;
- create, update, restore, attachment, slug, publish and unpublish lifecycle;
- independent Content and Storage authorization through Access;
- connection-bound Audit and database Search in the canonical transaction;
- read-only Filesystem inspection;
- request-local, Access-scoped Dataview projection with
  `ownsCanonicalRecords=false`;
- one anonymous, sessionless, read-only public JSON projection;
- file-backed SQLite, disposable marker-owned MySQL, fresh OS-process restart,
  migration rollback/reapply, atomicity and stale-head concurrency evidence.

The final author reproduction on the current tree passed `168 tests / 768
assertions`, including the real MySQL group, with PHPStan level 8 reporting no
errors. Detailed commands and bounded proofs are linked from the files in this
directory.

A clean-clone acceptance also exposed and closed one checkpoint-portability
defect: accepted launch receipts no longer contain checkout-specific absolute
paths. The package validator and a dedicated contract regression preserve the
relative ignored action-gate provenance reference and the committed toolchain
receipt in every fresh clone.

This package guarded-runtime checkpoint is accepted by independent
reverse-outcome review with P0=0, P1=0 and one bounded P2. The P2 concerns the
Search-provider lifetime in long-running runtimes and requires a future Search
owner factory contract before any long-running or production-readiness claim.

This evidence does not claim production readiness, frontend or admin-UI
readiness, release/update-server readiness, Root integration acceptance,
`larena.test` cutover, or readiness of all Larena packages.
