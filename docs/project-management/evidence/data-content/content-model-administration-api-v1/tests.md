# Verification receipt

Toolchain:

- PHP: `/Applications/ServBay/package/php/8.4/8.4.20/bin/php` (`8.4.20`);
- Composer: `/Applications/ServBay/bin/composer`;
- canonical Specs: `b5ea1bc2386544d4a6f4e4af4ce172a28988f0be`;
- exact Search revision: `9f5c1cf5d2b112751328520eee34826c19dd2535`.

Fresh dependency installation was reproduced after moving the previous
`vendor/` aside:

```text
php /Applications/ServBay/bin/composer install --no-interaction --prefer-dist
```

Result: PASS; 110 packages installed from `composer.lock`, including the exact
Search revision above.

Completed checks:

```text
php /Applications/ServBay/bin/composer validate --strict --no-check-publish
/Applications/ServBay/package/php/8.4/8.4.20/bin/php scripts/lint.php
/Applications/ServBay/package/php/8.4/8.4.20/bin/php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
/Applications/ServBay/package/php/8.4/8.4.20/bin/php vendor/bin/phpunit --configuration=phpunit.xml.dist --exclude-group=mysql
/Applications/ServBay/package/php/8.4/8.4.20/bin/php vendor/bin/phpunit --configuration=phpunit.xml.dist tests/Feature/ContentAdminApiHandlerRuntimeTest.php tests/Feature/ContentTypeSchemaVersionRuntimeTest.php tests/Feature/ContentAttachmentRuntimeTest.php tests/Contract/ContentAdminApiContractTest.php tests/Contract/ServiceInterfaceContractTest.php tests/Integration/ContentMigrationShapeTest.php tests/Unit/ContentOwnerAdapterTest.php
PATH="/Applications/ServBay/package/php/8.4/8.4.20/bin:$PATH" /Applications/ServBay/package/php/8.4/8.4.20/bin/php /Applications/ServBay/bin/composer run quality:gate
git diff --check
```

Results:

- Composer validation: PASS;
- syntax lint: 137 PHP files, PASS;
- PHPStan level 8: 0 errors;
- complete non-MySQL suite: 192 tests, 1347 assertions, PASS;
- focused admin handler, schema evolution, attachment Audit, OpenAPI and
  service-contract and locking-boundary suite: 42 tests, 607 assertions, PASS;
- focused coverage includes all 20 compiled handlers, duplicate type
  `409 type_already_exists`, same-field projection/metadata-only
  `409 type_version_no_change`, exact Content/Storage hash and identity drift,
  invalid persisted enums/projection, and private-path Audit redaction;
- discovered Content-before-Rest provider order: 3 tests, 85 assertions for
  the provider feature file, including invocation of all 20 handlers, PASS;
- complete package `quality:gate`: 195 tests, 1347 assertions, 3 isolated
  MySQL tests skipped without explicit credentials; validator, lint, PHPStan,
  evidence and scope checks PASS;
- whitespace check: PASS.

Isolated MySQL, final Root transport acceptance and independent review remain
Root/coordinator acceptance work and are not claimed here.
