# routes/ — HTTP & console route definitions

## Purpose
Declares every route the API exposes plus console commands. `api.php` is the single
source of truth for endpoints (the Swagger `@OA` annotations in controllers are
unreliable and sometimes describe different paths).

## Key files
- `api.php` — all REST endpoints under the `/api` prefix. Public auth/OTP/country
  routes at top; everything else inside one `Route::middleware('auth:sanctum')->group(...)`,
  organized by `Route::prefix('Stocks'|'item-category'|'customer'|'orders'|'payment'|'agent')`.
- `web.php` — single `GET /` returning the `welcome` view.
- `console.php` — default `inspire` command only (no scheduled/cron tasks).

## Data flow
Loaded by `bootstrap/app.php` via `withRouting(api: routes/api.php, web: …, commands: …,
health: '/up')`, which auto-applies the `/api` prefix + `api` middleware group. Each
route maps `Method + path → Controller@method`.

## Dependencies
References controllers in `app/Http/Controllers/` (+ `API/AuthController`). The
`auth:sanctum` guard depends on `config/sanctum.php` and the `personal_access_tokens`
table. This folder is loaded by `bootstrap/app.php`.

## Conventions
Action verbs: `/list`, `/add`, `/show/{uuid}`, `/update/{uuid}`, `/delete/{uuid}`.
⚠ Path casing is inconsistent (`/Stocks`, `/Country` vs `/item-category` vs `/customer`);
new paths should be lowercase kebab-case plural (PATTERNS §11). ⚠ Some routes point at
missing controller methods (`tokenCheck`, `logoutall`, `paymentHistory`).

## Common commands
- `php artisan route:list` — show resolved routes
- `php artisan route:list --path=api` — filter to API routes
