# Independent guarded-runtime review

Verdict: PASS
P0: 0
P1: 0
P2: 1

Review scope: the current `larena/content` guarded-runtime tree, its exact
eleven-package dependency closure, SQLite and MySQL behavior, public projection,
Search, Access, Audit, Storage, Filesystem, migration safety and Dataview
ownership boundaries.

The review found and required correction of the following blocking defects
before this verdict:

- Storage correlation results and logical-file persistence metadata were not
  checked against the exact owner contracts.
- update and restore did not prove the exact historical Storage schema version.
- attachment manifests could hide missing, mismatched or overflowing persisted
  rows.
- public reads could expose internal exceptions for malformed timestamps,
  persisted enum values and tampered-but-rehashed typed Storage values.
- create used a non-canonical lock order and invalid publication projections
  were not classified as one post-Access Content denial.

Each blocking finding now has a regression test. The independently reproduced
current gate is:

- full suite with real MySQL opt-in: 167 tests, 758 assertions, PASS;
- PHPStan level 8: 0 errors;
- PHP syntax lint: 123 files, PASS;
- package validator, scope check and `git diff --check`: PASS;
- disposable MySQL residue after the run: 0 schemas and 0 blob directories.

The remaining P2 is a bounded long-running-runtime concern. The Search source
registry currently retains a `DatabaseContentSearchSourceProvider` instance
whose dependencies are scoped. Correcting that cleanly requires a factory or
lazy-provider contract in the Search owner package; replacing one local
singleton would not fully solve the lifetime boundary. It does not block this
non-production Content Platform v1 checkpoint, and it must be resolved before a
long-running or production-readiness claim.

This verdict does not claim frontend or admin UI readiness, external Search,
public write APIs, row-level ACL, release/update-server readiness, production
readiness or readiness of the complete Larena package portfolio.
