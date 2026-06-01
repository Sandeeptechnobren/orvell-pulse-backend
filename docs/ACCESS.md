# Access & Credentials Guide

> Onboarding reference for new developers: what to get access to, and where each secret lives. **Never commit secrets** — `.env` files are gitignored; use your team's secret manager.

## Repositories
- Backend: `github.com/Sandeeptechnobren/orvell-pulse-backend` (default branch `ujjwal`; `main` is the docs/base branch).
- Frontend: `github.com/Sandeeptechnobren/orvell-pulse` (branch `main`).
- Request push access from the repo owner (Sandeeptechnobren). Use your own PAT or SSH key — never share tokens in plaintext.

## Environments
- Production API: `https://api.easycoders.in` (Laravel under `/projects/orvell/public/`, Apache).
- Local backend: `http://127.0.0.1:8000` (`php artisan serve`).
- Local frontend: `http://localhost:3000` (`npm run dev`).

## Backend env (`orvell-pulse-backend/.env`, copy from `.env.example`)
- `APP_KEY` — `php artisan key:generate`
- `DB_*` — SQLite by default; MySQL in production (DB user `orvell_user`; password from ops)
- `REDIS_*` — OTP cache
- `STRIPE_SECRET` — Stripe key ⚠️ NOT in `.env.example`; get from Stripe dashboard
- `WHAPI_MASTER_TOKEN` — WHAPI.cloud token ⚠️ NOT in `.env.example`; get from WHAPI.cloud
- OTP email goes through the external SchoolEXL API (endpoint hardcoded, no key needed)

## Frontend env (`orvell-pulse/.env.local`)
- `NEXT_PUBLIC_API_BASE_URL` — defaults to `https://api.easycoders.in`; point at your local backend for local integration.

## Third-party accounts
- Stripe (payments) · WHAPI.cloud (WhatsApp) · the production server (see `docs/SSH_CONFIG.md`).

## Server / SSH
- See `docs/SSH_CONFIG.md`. The server commands (`/deploy`, `/monitor`, `/logs`, `/status`, `/db`, `/test-live`) need a configured SSH host alias.

## Security reminders
- Rotate any token shared in plaintext (the clone token used during setup should be rotated).
- Keep `.env` / `.env.local` gitignored.
- Full secret/config inventory: `docs/CODEBASE_AUDIT.md` §9.
