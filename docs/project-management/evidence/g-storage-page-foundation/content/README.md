# S-CONTENT-01-DEPENDENCY-CORRECTION evidence

The package owns a strict EditorJS-compatible JSON document contract and stores
each revision through `larena/storage`. The canonical state contains versioned
block data, stable IDs, explicit registry identity and an explicit scope; it
does not contain rendered HTML.

Paragraph and logical-image blocks migrate deterministically from version zero
to version one. Unknown blocks and versions fail closed. Sanitization permits
plain content while rejecting script-like, executable and data-HTML payloads.
Document size, depth, block count and payload sizes are bounded. Writes use
Storage optimistic revisions and same document IDs remain isolated by scope.
The correction successor requires a package-owned authorization port for every
create, update, read and projection. Its Access adapter proves the operation
and exact requested scope before record access; the concrete global-role
identity adapter is accepted only for `scope:global`. Image references are
inspected before writes and re-inspected before projection, so missing,
deleted, temporary and scope-reassigned logical files fail closed.

The focused correction suite uses real persisted Access identities and covers
foreign reader read/project, foreign editor create/update, authorized
same-scope behavior, missing/deleted/ineligible/stale file references and exact
before/after mutation equality.

The dependency correction binds Composer package definitions, lock metadata,
launch context and the exact compatibility contract to Access
`71fb85a5ce407720e1c5afee4294d2cd098bd596`, Filesystem
`6b7c240c88391338022e800231104c94279badaa` and Storage
`17c4fc6d0f88a148e5c14ab2af904236e483bf50`. The Storage owner-interface test
enumerates its complete public method set, including `listCurrentRecords`, so
future unexpected additions remain a hard failure.

Readiness claim: `TESTED`, pending independent audit. This evidence does not
claim Root integration, adoption, merge readiness, browser UI or production
readiness.
