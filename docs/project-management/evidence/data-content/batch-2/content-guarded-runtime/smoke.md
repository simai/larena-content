# Guarded runtime smoke

The smoke path starts from a fresh file-backed SQLite database, applies the
exact Access, Audit, Storage, Search, Filesystem and Content migrations, seeds
two persistent administrator identities and assigns the accepted system roles.

It then:

1. registers `article` and `event` Content types with string, integer and
   boolean fields;
2. creates draft items whose typed values live only in Storage;
3. updates and restores an article as immutable revisions;
4. associates persistent logical files without writing a blob through
   Content;
5. publishes a public revision and reads the exact anonymous projection;
6. queries the matching Search document and rebuild source;
7. materializes Access-scoped Dataview rows without values or attachments;
8. unpublishes and verifies the public projection disappears while Search
   retains a newer tombstone;
9. closes the first runtime and verifies item, public projection, Search,
   Access and Storage state from a new PHP OS process.

The same core corpus runs against a unique local MySQL database whose name is
strictly allowlisted and whose ownership is proven by a per-run marker row.
Cleanup verifies that marker before dropping the database. The final
reproduction left zero matching schemas and zero matching temporary blob
directories.

Negative smoke proves fail-closed behavior for:

- forbidden actors and duplicate-domain denials;
- stale expected revisions;
- private items and draft projections;
- invalid public lifecycle enums and null publication timestamps;
- missing, overflowing or tampered attachment manifests;
- corrupted Content-to-Storage schema pointers;
- tampered but rehashed Storage public projections;
- Search, Audit and Storage participant failures.

The smoke does not boot or mutate `larena.test`, perform Root integration,
exercise an admin UI, publish frontend assets, deploy a release or assert
production readiness.
