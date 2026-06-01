# app/Services/ — business logic layer

## Purpose
Encapsulates business logic and database work so controllers stay thin. Each service owns
its model's queries and wraps writes in `DB::transaction`. This is where the real work lives.

## Key files
- `Item_categoryService.php` — canonical CRUD template (`list / create / getByUuid /
  updateByUuid / deleteByUuid`).
- `StockManagementService.php` — stock CRUD; resolves the client's `Space` (auto-creating a
  default), stamps `client_id` / `created_by`.
- `CustomerManagementService.php` — customer CRUD.
- `OrderService.php` — order queries (eager-loads space/customer/product relations).
- `WhatsappMessageService.php` — WHAPI.cloud integration (create channel, fetch QR, store
  agent prompt); hardcoded `projectId`; reads `env('WHAPI_MASTER_TOKEN')`.

## Data flow
Controller calls `$this->service->method($validatedData)`; the service runs Eloquent queries
inside `DB::transaction` and returns models/collections back to the controller for resource
wrapping.

## Dependencies
Depend on `app/Models/`. Injected into controllers via constructor. `WhatsappMessageService`
also makes external HTTP calls (Guzzle / `Http` facade).

## Conventions
Methods named `list / create / getByUuid / updateByUuid / deleteByUuid`; writes wrapped in
`DB::transaction`; single-record fetch via `where('uuid',$uuid)->firstOrFail()`. Plain PHP
classes (no artisan generator). ⚠ Auth/Payment/Country controllers bypass this layer (hit
Eloquent directly) — new features SHOULD use a service.

## Common commands
- No generator — create `XService.php` by hand (namespace `App\Services`).
- `php artisan tinker` — exercise service methods.
