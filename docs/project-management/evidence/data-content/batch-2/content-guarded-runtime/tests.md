# Tests

Pinned execution context:

- PHP `8.4.20`;
- Composer `2.7.1`;
- Composer platform PHP `8.3.31`;
- Specs launch commit
  `2c3c3106a4afb98925dbf8192cfaadb57ca4d4a9`;
- exact accepted eleven-package revisions from `composer.lock`.

Final author reproduction on the current Content tree:

- full exact-vendor PHPUnit, with the real MySQL opt-in enabled:
  `167 tests, 758 assertions`, all passing in `61.601s`;
- PHPStan level 8 with a `1G` memory limit: `No errors`;
- PHP lint: `123 PHP files`, all passing;
- package validator, Composer strict validation, scope check and
  `git diff --check`: passing;
- the real MySQL tests removed every disposable schema and temporary blob
  directory after verification.

The exact-vendor PHPUnit run used an explicit bootstrap at
`/private/tmp/larena-content-platform-v1/vendor-exact/autoload.php`. This
prevents the repository's pre-existing development vendor checkout from
silently replacing the revisions recorded in `composer.lock`.

MySQL acceptance was explicitly opted in with
`LARENA_CONTENT_MYSQL_TEST=1` and an absolute
`LARENA_CONTENT_MYSQL_ENV_FILE=<ignored-file>` reference. The local file was
untracked, ignored and mode `0600`; no credential value is present in this
evidence.

Required package commands:

```text
composer validate --strict
composer run validate:larena
composer run lint
composer run analyse
composer run test:contract
composer run test:unit
composer run test:feature
composer run test:sqlite
composer run test:mysql
composer run test:concurrency
composer run test:http
composer run test:rollback
composer run test
composer run evidence:check
composer run scope:check
git diff --check
```

`composer run evidence:check` is green after the separate reviewer recorded
`independent-review.md` with P0=0, P1=0 and P2=1 and the coordinator applied
that exact verdict to `.larena/launch-context.json`. The author bundle did not
pre-author or simulate the independent verdict.

Runtime test coverage includes:

- two unrelated arbitrary typed schemas (`article` and `event`);
- type create/read/list and exact Storage schema ownership;
- item create/read/list/update and typed-value non-duplication;
- immutable revision history and restore as a new draft;
- attachment attach/detach/reorder, private filtering and overflow/corruption
  rejection;
- type-and-locale-scoped slug collision and republish route movement;
- public projection, Search equivalence, reindex source and tombstones;
- draft/private/corrupt state non-disclosure;
- Access single-denial and Content domain-denial separation;
- Storage, Search and Audit rollback;
- fresh OS-process restart;
- migration no-op, rollback and reapply;
- SQLite and MySQL stale-head one-winner behavior.
