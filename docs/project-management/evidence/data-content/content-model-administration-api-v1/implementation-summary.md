# Implementation summary

The package now publishes a closed `api.yaml` with exactly 20 draft/v1
administrator operations under `/api/v1/admin/content`. `larena/rest` remains
the transport owner; Content registers package handlers lazily and owns only
business orchestration and safe response mapping.

Typed values cross the API as a bounded ordered list. Duplicate keys, unknown
members, unsupported value shapes and unsafe metadata fail closed. Detailed
reads reconstruct values from exact Storage versions in Content schema order;
mutation responses stay bounded and do not perform undeclared owner reads.
Schema-version create returns only the known type head and does not rehydrate
the just-written Content/Storage version.

Content type evolution is full-candidate, optional-append-only. Preview checks
the expected type head, exact Content/Storage owner set and Storage
compatibility without domain mutation. Create performs Storage analyze, plan
and apply plus immutable Content type/item revisions and compare-and-swap head
updates in one transaction. Existing published pointers and Search projection
remain on the old exact published revision until explicit publication.
Every accepted version appends at least one optional field and retains strict
Content type version `N` to Storage schema version `N` topology. A same-field
candidate returns `409 type_version_no_change` even if its projection or safe
metadata differs; Content creates no metadata-only or fake Storage no-op
version.

Historical restore reads the selected immutable Storage version, normalizes it
against the current type schema and writes a new current-schema draft. It
never republishes implicitly.

The service provider registers all 18 Content Access operations, 12 Content
Audit events, lazy per-dispatch Rest handlers and a lightweight
container-backed Search source factory. Clearing the container scope resolves
a new Search source and connection graph.

Stable domain reasons are translated to the frozen Rest status family.
Duplicate Content type creation returns the uniqueness conflict
`409 type_already_exists`, rather than being collapsed into validation 422.
Detailed reads verify exact item/revision/type/Storage identities and the
persisted Content schema hash against the owner Storage schema hash. Invalid
persisted enums, projection contracts and attachment manifests become a
sanitized integration failure. Invalid logical-file paths never enter
attachment-denial Audit context.

No Content-owned table was added. The package still owns exactly the six
accepted Content tables. It adds no blob mutation, generic controller,
arbitrary callable dispatch, public write route, frontend, deployment or
production-readiness claim.
