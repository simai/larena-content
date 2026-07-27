# CMS content model v1 Content implementation

Larena Content now owns the CMS v1 backend model on its existing six-table persistence boundary.

- Administrators define content types from the stable service/API contract with `string`, `text`, `number`, `boolean`, `date`, `file`, and `relation` fields.
- Property performs typed normalization; canonical decimal values remain lossless strings.
- Files are validated through the Filesystem inspection contract. Relations are validated against Content-owned item identities.
- Public publication fails closed for non-public files and relations whose target has no published revision.
- Editor can create, update, and submit an immutable review revision but cannot publish. Reader remains read-only. Administrator publishes, unpublishes, and restores.
- Mutations retain transaction, Access, Audit, Storage, Search, and Filesystem package boundaries; typed values, passwords, tokens, file contents, and private fields are absent from Content Audit payloads.
- The admin execution surface is the compiled package-owned REST contract (21 operations). No frontend-completeness claim is made.

No Content migration was added: the existing status column already stores the package-owned enum values, so the new `review` state is backward-compatible with the accepted schema.
