# Tests

Pinned toolchain:

- PHP 8.4.20;
- Composer 2.7.1;
- Composer platform PHP 8.3.31.

Fresh accepted checkpoint results:

- PHPStan level 8: `No errors`;
- PHPUnit: `75 tests, 314 assertions`, all passing;
- PHP lint: `64 files`, all passing under `PHP_BINARY`;
- Composer scripts use `@php`, so the recorded PHP executable is inherited
  without a hidden shell `PATH` dependency;
- contract fixtures cover all seven frozen features;
- the Composer lock compatibility test verifies the exact accepted
  eleven-package Larena closure.

The final checkpoint run executed:

```text
composer validate --strict
composer install --no-interaction --prefer-dist
composer run validate:larena
composer run lint
composer run analyse
composer run test
composer run evidence:check
composer run scope:check
composer run quality:gate
git diff --check
```

All commands passed with the pinned PHP 8.4.20 executable and Composer 2.7.1.

These are package interface tests. They do not substitute for guarded-runtime,
SQLite/MySQL, HTTP, restart or rollback/reapply acceptance.
