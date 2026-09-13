# app/Http/Controllers/ — HTTP request handlers

## Purpose
Controllers receive (validated) requests, delegate to a `*Service`, and return JSON via
an API Resource and/or `ResponseTrait`. They are meant to be thin. The live auth
controller lives in the `API/` subfolder.

## Key files
- `API/AuthController.php` — signup/signin/OTP/password-reset/logout; mints Sanctum tokens
  for `Client`; stores OTP in Redis; calls the SchoolEXL OTP API.
- `StockManagementController.php` — Stocks CRUD (uses `StockManagementService` + Resource).
- `Item_categoryController.php` — Item Category CRUD (cleanest module template).
- `CustomerManagementController.php` — Customer CRUD.
- `OrderController.php` — order list/show.
- `PaymentController.php` — Stripe charge + history (⚠ method `paymentHistoryy` typo).
- `WhatsappMessageController.php` — agent init / store prompt (WHAPI).
- `CountryController.php` — public country list (⚠ no `countries` table → 500).
- `Controller.php` — empty base class.
- ⚠ `AuthhController.php` — dead unrouted duplicate; do NOT use. `Bale/Buyer/…Controller.php` — empty scaffolds.

## Data flow
Route → constructor injects `*Service` → method receives a typed `FormRequest` →
`$this->service->…($request->validated())` → wrap result in a `Resource` →
`response()->json(...)` (or `ResponseTrait::success/fail`).

## Dependencies
Depend on `app/Services/`, `app/Http/Request/`, `app/Http/Resources/`, `app/Traits/ResponseTrait`.
Invoked by `routes/api.php`.

## Conventions
Methods `index/store/show/update/destroy`; inject the service in `__construct`.
⚠ Response envelope is inconsistent (some use `ResponseTrait::success`, most return raw
`{message,data}`) — standardize on `ResponseTrait` (PATTERNS §10). ⚠ `StockManagementController`
calls a non-existent `$this->error()`; use `fail()`.

## Common commands
- `php artisan make:controller XController`
- `php artisan route:list`
