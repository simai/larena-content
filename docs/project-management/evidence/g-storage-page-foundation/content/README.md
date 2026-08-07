# S-CONTENT-01 evidence

The package owns a strict EditorJS-compatible JSON document contract and stores
each revision through `larena/storage`. The canonical state contains versioned
block data, stable IDs, explicit registry identity and an explicit scope; it
does not contain rendered HTML.

Paragraph and logical-image blocks migrate deterministically from version zero
to version one. Unknown blocks and versions fail closed. Sanitization permits
plain content while rejecting script-like, executable and data-HTML payloads.
Document size, depth, block count and payload sizes are bounded. Writes use
Storage optimistic revisions and same document IDs remain isolated by scope.

Readiness claim: `TESTED`, pending independent audit. This evidence does not
claim Root integration, adoption, merge readiness, browser UI or production
readiness.
