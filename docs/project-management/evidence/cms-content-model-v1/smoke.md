# Smoke

Package-owned smoke is complete:

1. Administrator creates an arbitrary CMS type containing all seven required field families.
2. Typed values are persisted by Storage; file and relation ownership checks fail closed.
3. Editor creates a draft and appends an immutable review revision.
4. Editor publication and Reader mutation are denied by Access.
5. Administrator publishes; Search and the anonymous published reader receive only the exact public projection.
6. A public relation to an unpublished target is rejected atomically.
7. Audit records lifecycle operations without typed field values or file contents.

Root distribution and browser smoke are intentionally deferred to the integration stage.
