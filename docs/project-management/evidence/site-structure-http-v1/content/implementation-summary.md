# Site structure HTTP v1 — Content package

Status: package verification passed.

`larena/content` now owns an operational server-rendered HTTP surface for its
existing canonical features `content.site_structure_v1`,
`content.seo_metadata_v1` and `content.managed_redirects_v1`.

## Implemented

- opt-in protected Admin routes under `/admin/content/site-structure`;
- shared Larena Admin shell contribution and existing Simai Framework asset and
  smart-component reuse;
- Reader read-only view, Editor draft/submit flow, Administrator publish and
  restore flow;
- CSRF-bearing forms and expected-revision compare-and-swap on every mutation;
- versioned navigation and SEO editing without a client-side state store;
- managed redirect inspection without a manual mutation bypass;
- anonymous `/content/navigation`, `/robots.txt`, `/sitemap.xml`;
- canonical `Link` and `X-Robots-Tag` headers on published Content;
- existing one-hop permanent redirect behavior retained.

The canonical backend service remains the only state mutation owner. Admin
controllers transform validated form values into existing value objects and
delegate authorization, transactions, CAS and Audit to the Content runtime.

## Nonclaims

- `production_ready=false`
- `frontend_complete=false`
- `all_42_packages_ready=false`
- no separate navigation, SEO or redirect package
- no manual redirect creation

