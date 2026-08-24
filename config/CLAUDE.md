# config/ — framework & integration configuration

## Purpose
16 Laravel config files defining DB connections, auth/Sanctum, CORS, queue/cache/session
drivers and mail. Values are pulled from `.env`.

## Key files
- `database.php` — 5 connections; default `sqlite` (MySQL in prod).
- `auth.php` — default guard `web` → `App\Models\User` (note: real auth principal is `Client`).
- `sanctum.php` — token guard; `expiration => null` (no server-side expiry).
- `cors.php` — ⚠ `allowed_origins => ['*']` (wildcard).
- `services.php` — postmark/resend/ses/slack stubs; ⚠ no Stripe/WHAPI entries.
- `permission.php` — spatie config (installed but dormant; no model uses `HasRoles`).
- `queue.php` / `cache.php` / `session.php` — all default to the `database` driver.
- `mail.php` — default `log` mailer. `passport.php` — ⚠ orphaned (Passport not installed).

## Data flow
Read at boot via `config('...')`. `.env` values feed config keys. After
`php artisan config:cache`, only `config()` reads work — scattered `env()` calls return null.

## Dependencies
Backs every module (DB, auth, integrations, queue). Read indirectly by services/controllers.

## Conventions
Read config via `config('services.x')`, never `env()` in app code. Put new third-party
credentials in `services.php` (e.g. add `stripe`, `whapi` keys).

## Common commands
- `php artisan config:clear` · `php artisan config:cache`
