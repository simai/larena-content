# Code review feedback

Review scope: guarded Content runtime, owner-package boundaries, migration
shape, public and Search projections, provider composition, package tests and
disposable database evidence.

Resolved during author and reverse-outcome review preparation:

- Storage correlation verification now checks the deterministic
  owner-produced correlation form instead of requiring impossible equality
  with the caller input.
- Filesystem owner inspection maps only the frozen safe metadata allowlist and
  keeps physical and internal locator data out of Content DTOs.
- list operations apply connection and actor preflight before bounded Access
  query scope and protected reads.
- Content verifies the exact Search write result instead of accepting an
  unrelated or stale owner result.
- optional null submitted fields are normalized as omission while required
  null values remain rejected.
- Content and owner participant timestamps are normalized to the accepted UTC
  precision and Audit events remain inside the caller transaction.
- public read translates corrupt/infrastructure projection state to one
  uniform not-public result without denial Audit or state healing.
- attachment reads fetch one row beyond the declared maximum so 101 persisted
  rows cannot be truncated into an apparently valid 100-row manifest.
- missing rows, attachment-count mismatch, invalid persisted enums, null
  publication timestamp, corrupted schema version and tampered Storage
  projection all have executable fail-closed regressions.
- Access denial remains exactly one Access event; post-Access Content domain
  rejection remains exactly one Content denial.
- MySQL evidence uses a strict database-name allowlist, a per-run ownership
  marker, server-identity preflight and verified `finally` cleanup.
- restart evidence now crosses an actual PHP OS-process boundary rather than
  merely constructing a second object in the same process.
- accepted launch metadata no longer embeds checkout-specific absolute action
  gate or generated-report paths; a clean-clone contract now preserves the
  relative provenance reference and committed toolchain receipt.

Final author reproduction is green at `168 tests / 768 assertions`, PHPStan
level 8 with no errors, lint of 123 PHP files, package validation, scope and
diff checks.

The independent acceptance verdict remains separately attributable in
`independent-review.md`; this author feedback file does not replace it.
