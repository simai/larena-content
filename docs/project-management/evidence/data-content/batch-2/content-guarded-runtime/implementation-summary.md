# Implementation summary

Content Platform v1 is implemented as a bounded Laravel package runtime over
seven accepted owner packages.

Content owns exactly these six tables:

1. `larena_content_types`;
2. `larena_content_type_versions`;
3. `larena_content_items`;
4. `larena_content_item_revisions`;
5. `larena_content_item_revision_attachments`;
6. `larena_content_routes`.

Typed field values are never copied into those tables. Content registers an
immutable type-derived schema in Storage and stores only exact Storage schema,
record and record-version references in immutable Content revisions. Only
create, value update and restore append Storage record versions. Attachment,
reorder, publish and unpublish mutations append Content revisions while
reusing the exact immutable Storage reference.

Protected operations require the canonical administrator identity form
`user:admin_identity:<positive integer>`. Content checks its own Access
operation and the exact required Storage operations before protected
existence disclosure. Access owns authorization denials; Content emits one
`content.operation.denied` only for post-Access domain rejection.

The mutation transaction uses one exact database connection across Content,
Storage, Search and Audit. It performs Content persistence, owner writes where
required, Search projection/tombstone changes and the Content Audit event
atomically. Search, Audit and Storage failure tests demonstrate rollback of
all canonical participants.

Filesystem integration is read-only. Content persists lowercase logical-file
UUID references and asks the owner inspector for attachability and
public-projectability. Public projection re-inspects the exact published
manifest, filters non-public files, reindexes public positions contiguously
and rejects missing, overflowing or count-mismatched persisted manifests
without healing them.

Publication maintains independent current and published pointers. A newer
draft can coexist with the older published projection. Republish moves the
public slug and replaces the Search document at a strictly newer Content
revision; unpublish removes the public route and leaves a Search tombstone.
Corrupt lifecycle, enum, timestamp, attachment, Storage-schema or Storage
projection state fails closed.

The Search source provider rebuilds from the same `PublishedContentReader`
projection used by the anonymous route. Dataview first calls the Access-scoped
Content list service, materializes at most 100 scalar/null presentation rows
for the request, exposes no field values or attachments and owns no canonical
records.

The package provider binds the runtime contracts, registers the sixteen
protected Content operations and the `content.published_items` Search source,
loads the single Content migration and exposes only
`GET /content/{type_key}/{slug}?locale=en` as an anonymous read surface.

Bounded exclusions remain unchanged: no type evolution, archive workflow,
review workflow, upload/write Filesystem API, external Search/outbox, admin
UI, frontend, Docara adapter, Root composition change, deployment or
production-readiness claim.
