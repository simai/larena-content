# Site structure backend v1

`larena/content` now owns one versioned `primary` site-structure aggregate. Its
immutable revisions contain the ordered navigation tree and page SEO metadata,
so draft, review, publish and restore are atomic. Access grants Editor draft and
review preparation, Administrator publication and restore, and Reader protected
read-only access.

Content publication reserves a managed redirect whenever a previously
published slug changes. Redirect state stores the source locator and target
Content UUID; anonymous resolution computes the current published locator and
therefore cannot expose a chain or cycle. Current and published route claims,
redirect sources, duplicate canonicals and unsafe external URLs fail closed.
Restoring an older published slug can reclaim a redirect owned by the same
Content item; the service removes the obsolete owned source and creates one
reverse redirect. Redirects owned by another item remain conflicts.

The public sessionless endpoints expose only the exact published structure and
published Content. SitePack exports, verifies, plans and imports structure
heads, every immutable structure revision, node UUIDs, SEO metadata and managed
redirect identities together with their Content targets. Import is
transactional and exact replay is a no-op.

No separate package or second navigation store was added. Link remains owner of
token, action and share-link mechanics; Layout remains a read-only consumer.
