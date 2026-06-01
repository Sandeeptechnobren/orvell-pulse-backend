# tests/ — PHPUnit test suites

## Purpose
Automated tests. Currently only skeleton examples exist — there is NO real coverage of
models/services/controllers/endpoints. Use these files as the structural template when
adding tests.

## Key files
- `TestCase.php` — base class (extends Laravel's `BaseTestCase`); Feature tests extend
  this to boot the framework.
- `Feature/ExampleTest.php` — integration example: `GET /` asserts 200 (`RefreshDatabase` commented out).
- `Unit/ExampleTest.php` — unit example: extends `PHPUnit\Framework\TestCase`, asserts true.
- `../phpunit.xml` — suites (Unit/Feature) + test env (`sqlite :memory:`, array cache/mail/session,
  `QUEUE_CONNECTION=sync`, `BCRYPT_ROUNDS=4`).

## Data flow
`php artisan test` boots the app with the testing env from `phpunit.xml`, runs both
suites, and reports results. Feature tests hit routes; Unit tests run in isolation.

## Dependencies
Feature tests exercise `routes/` → controllers → in-memory sqlite DB. Depend on
`phpunit.xml`. Nothing in the app depends on `tests/`.

## Conventions
Feature tests extend `Tests\TestCase`; Unit tests extend `PHPUnit\Framework\TestCase`.
Method names are `snake_case`, prefixed `test_`. Add `use RefreshDatabase;` for DB-touching
feature tests. (See PATTERNS §7.)

## Common commands
- `composer test` (runs `config:clear` then `php artisan test`)
- `php artisan test` · `php artisan test --testsuite=Feature` · `--filter=TestName`
- `./vendor/bin/phpunit`
