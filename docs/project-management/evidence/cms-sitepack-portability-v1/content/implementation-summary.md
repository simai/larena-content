# CMS SitePack portability adapter

`CmsSitePackService` is the Content-owned administration boundary for CMS
portability. It exports a deterministic SitePack v0.4 archive, verifies its
manifest and graph, plans a no-write import and applies compatible imports in
one ambient database transaction. Content UUIDs, type versions, immutable
revision chains, publication pointers, routes, typed values, relations and
attachments are preserved.

Logical files cross the boundary only through `PortableLogicalFileStore`.
Their portable descriptors contain stable logical/public identities and safe
metadata, never a disk, storage key or absolute path. New blobs have explicit
compensation if the surrounding import rolls back.

Administrator authorization is checked before package access. Reader and
Editor are denied. Success and failure events contain only bounded counts,
opaque package references and digests; credentials, values, file bytes and
physical paths are forbidden.
