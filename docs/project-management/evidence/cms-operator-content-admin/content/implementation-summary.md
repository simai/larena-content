# CMS operator Content browser surface

The package now owns supported browser screens for content-type creation,
generic material authoring, protected preview, review, publication,
unpublication and revision restoration. The screens call the existing typed
`ContentTypeService`, `ContentItemService` and `ContentAdminReadModel`
contracts; they do not call the internal REST API or create another data
store.

The content-type form covers `string`, `text`, `number`, `boolean`, `date`,
`file` and `relation`. File choices come from the Filesystem-owned
`ManagedFileSnapshotSource`; relation choices use canonical Content item
references. A separate anonymous HTML page renders only the published public
projection. Stored material state remains typed JSON-backed data rather than
HTML.

Package ownership is preserved: Content owns feature screens and actions,
Admin/UI own the shell and Smart primitives, and Root remains composition-only.

Nonclaims: `production_ready=false`, `frontend_complete=false`,
`all_42_packages_ready=false`.
