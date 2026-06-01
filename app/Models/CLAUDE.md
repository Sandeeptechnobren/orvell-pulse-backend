# app/Models/ — Eloquent models

## Purpose
Eloquent ORM models mapping to DB tables. 15 are active; 10 are empty scaffold stubs
(logistics/invoicing domain). Several generate a `uuid` and stamp `created_by/updated_by`
in a `boot()` hook.

## Key files
- `User.php` — default auth user (`HasApiTokens`).
- `Client.php` — the REAL authenticated principal (signup/login); `HasApiTokens`, M:N `Customer`.
- `Customer.php`, `Space.php`, `space_iq.php` — tenant/space domain.
- `StockManagement.php` — maps to table **`products`**; auto-generates `sku` (STK0001…).
- `Item_category.php` — auto-generates `code` (CAT001…); clean `boot()` template.
- `Order.php`, `Payment.php`, `CustomerManagement.php`, `Country.php` — feature models.
- `AgentDetails.php`, `agentPrompt.php` — WhatsApp agent data.
- Stubs: `Buyer/Bale/BaleBatch/Container/Reservation/Invoice/InvoiceItem/Release/DailySnapshot/WhatsappMessage`.

## Data flow
Used by services for queries; transformed by resources for output. Written via mass-assignment
(`$fillable`) inside `DB::transaction`.

## Dependencies
Map to `database/migrations/`. Consumed by `app/Services/` and `app/Http/Resources/`.
⚠ `Client`/`Customer` reference a non-existent `Appointment` model (`->appointments()` throws).

## Conventions
`PascalCase` class names (⚠ `space_iq`, `agentPrompt` are lowercase — avoid in new code);
`$fillable` arrays; `uuid` + `SoftDeletes` common; UUID/blame logic duplicated across many
`boot()` hooks (candidate for a shared trait). ⚠ Keep `$fillable` aligned with the migration
columns — drift exists (audit §4).

## Common commands
- `php artisan make:model X -m` (model + migration)
- `php artisan tinker` — interact with models
