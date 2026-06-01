# database/ — migrations, seeders, factories

## Purpose
Defines the relational schema (19 migrations), seed data, and model factories. Default
connection is SQLite (`database/database.sqlite`); MySQL in production.

## Key files
- `migrations/0001_01_01_*` — framework tables (users, cache, jobs, sessions).
- `migrations/2025_11_15_065642_create_permission_tables.php` — spatie roles/permissions.
- `migrations/*_create_{orders,item_category,customer_management,payments,personal_access_tokens}_table.php` — active feature tables.
- `migrations/2025_11_15_*` (buyers, bales, containers, invoices, …) — empty scaffold tables.
- `seeders/DatabaseSeeder.php` — default only (one test user; no countries/roles data).
- `factories/UserFactory.php` — the only factory present.
- `database.sqlite` — the working SQLite DB file.

## Data flow
`php artisan migrate` builds tables from `migrations/`; `app/Models/` map onto them;
`db:seed` runs `DatabaseSeeder`.

## Dependencies
Consumed by `app/Models/` (Eloquent). Connection config lives in `config/database.php`.

## Conventions
snake_case columns; timestamps + (often) `uuid` + soft-deletes. ⚠ CRITICAL DRIFT: code
references ~10 tables with NO migration (`clients`, `products`, `countries`, `spaces`,
`space_iqs`, `agentDetails`, `agentPrompt`, `client_customer`, `otp_codes`) — see
`../docs/CODEBASE_AUDIT.md` §4. New tables: add migration + matching model `$fillable` + seeder.

## Common commands
- `php artisan migrate` / `migrate:fresh` / `migrate --graceful`
- `php artisan db:seed` · `php artisan make:migration create_x_table`
