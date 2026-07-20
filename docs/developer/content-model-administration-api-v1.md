# Content Model Administration API v1

Status: draft development contract. This document does not claim production
readiness.

## Ownership

`larena/content` owns content types, immutable type versions, item and revision
heads, publication pointers and logical-file bindings. Typed values and schema
migration records remain owned by `larena/storage`. `larena/rest` owns HTTP
routing, administrator-session validation, CSRF, rate limits, durable
idempotency, transaction finalization, error envelopes and generated OpenAPI.

The package publishes 20 closed operations from `api.yaml` under
`/api/v1/admin/content`. No arbitrary class or method dispatch is exposed.
Every operation has an exact Access list, bounded request/response schemas and
a package handler reference.

GET operations need an administrator session but no CSRF or idempotency key.
POST, PUT and DELETE operations require the administrator session, CSRF and
`Idempotency-Key`; Rest owns their transaction. Schema preview follows the
mutation transport rules because its protected Audit result is replayed
idempotently.

## Typed values

Dynamic Content values cross the closed Rest schema as an ordered list:

```json
[
  {"key": "title", "value": "Example"},
  {"key": "featured", "value": true}
]
```

The handler rejects duplicate keys, non-scalar values, extra keys and more than
100 entries, then converts the list to the existing keyed Content service
input. Read responses convert owner values back to the type-definition order.
Mutation responses contain only bounded Content head summaries; they do not
perform undeclared Storage reads. Type-version create returns
`{"type":{"type_key":"article","current_version":2}}` from the already-known
committed target head and does not rehydrate the immutable version after the
write.

## Additive type versions

`previewVersion` and `createVersion` accept the complete candidate fields,
projection and safe metadata together with `expected_version`.

The accepted v1 evolution rule is:

- all existing fields remain in the same order with identical key, Property
  type, visibility, required flag and constraints;
- every new type version appends at least one optional field;
- projection and safe-metadata changes are accepted only together with that
  optional field append;
- an unchanged field list is always rejected as
  `409 type_version_no_change`, even when projection or safe metadata differs;
- destructive changes fail before Storage mutation.

Preview locks and verifies the expected Content head and complete
Content/Storage owner set, asks Storage for a compatibility report and writes
only sanitized Audit events.

Create repeats that preflight inside one transaction, obtains a single-use
Content owner capability, asks Storage to plan and apply the migration, then
appends one draft Content revision for every current item. Item heads, routes
and the type head advance through compare-and-swap. Any failure rolls back the
Storage plan/result rows, Storage revisions, Content revisions, heads, routes
and success Audit events together.

Content type version `N` points to Storage schema version `N`; this strict
one-to-one topology is retained by both preview and create. Content does not
create a projection-only, metadata-only or fake Storage no-op version.

Existing published pointers are preserved. Therefore a migrated v2 draft does
not replace the public v1 projection until an explicit publish operation.

Detailed reads verify the persisted Content type hash against the exact
Storage schema hash and fail closed on invalid persisted enums, projection
contracts, attachment manifests or Content/Storage identity drift. The Rest
boundary exposes one sanitized integration failure and does not leak private
persisted values. Invalid logical-file paths are likewise omitted from denial
Audit payloads.

## Historical restore

Restore reads the selected immutable historical Storage version, normalizes
its values against the current Content type schema and appends one
compare-and-swap Storage/Content revision using the current schema version.
Missing optional fields stay absent. Restore never republishes implicitly.

## Runtime lifetimes

The Rest registry stores lightweight closures and resolves the scoped Content
handler on every dispatch. The Search registry stores a lightweight source
factory; database-bound Content search sources are created only when a reindex
batch is requested. Neither singleton registry retains a scoped database
connection.

## Verification

Run with the pinned PHP/Composer toolchain from `.larena/launch-context.json`:

```bash
composer install --no-interaction --prefer-dist
composer run quality:gate
```

Focused PHPUnit coverage compiles the real `api.yaml`, generates OpenAPI,
round-trips all 20 handlers through their compiled request and response
schemas, verifies lazy resolution, exercises additive migration and
cross-schema restore, rejects same-field projection/metadata-only candidates,
and checks corruption boundaries, safe Audit payloads and rollback behavior.
