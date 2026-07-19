# Migration rollback and reapply

Content Platform v1 uses one migration and exactly six owned tables.

Accepted transitions:

- no Content tables to all six exact tables;
- all six exact tables to an idempotent no-op;
- all six exact and empty tables to no Content tables;
- empty rollback state back to all six exact tables;
- an exact-shaped completely empty partial topology is removed in reverse
  dependency order and recreated as the full topology.

Fail-closed transitions:

- any non-empty partial topology is rejected before DDL;
- any incompatible owned-table shape is rejected before DDL;
- rollback with any Content-owned row is rejected before dropping a table.

The SQLite migration-shape corpus exercises full creation, exact no-op,
rollback/reapply, empty partial repair and non-empty/incompatible refusal.

The real MySQL proof adds a sentinel row to the full exact topology, invokes
`up()` again and confirms both the row and topology are unchanged. It then
removes the sentinel, performs empty `down()`, verifies all six tables are
absent, reapplies `up()` and runs the two-type publication corpus.

No vendor-specific foreign-key toggling or data-destructive repair is used.
Rollback acceptance applies only to this package migration in its disposable
acceptance databases; it is not a live deployment or production rollback
claim.
