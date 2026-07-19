# Interface smoke

The interface smoke verifies that:

- package autoload works without booting a Laravel application;
- every public contract and value object loads without a database;
- Access, Audit, Search and Dataview PHP catalogs match their descriptor
  intent;
- public and Search projections reject invalid shapes and expose only the
  frozen safe surface;
- dependency interface signatures and exact revisions remain compatible;
- no forbidden runtime directory or mutation surface exists.

The smoke intentionally does not create tables, write Storage records, inspect
live Filesystem data, mutate Search, emit Audit records or register providers.

Accepted result: PHPUnit `75 tests / 314 assertions`, PHPStan level 8, lint of
64 PHP files, package validation, evidence validation, 84-file scope and the
aggregate quality gate are green under the pinned toolchain.
