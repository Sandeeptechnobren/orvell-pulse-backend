# Tasks

Task tracking. `/plan` adds items under **In Progress**; `/done` moves them to **Completed**.

## In Progress
_(none)_

## Completed
- 2026-06-01 — Add AI-assisted development context layer (CLAUDE.md hierarchy, `docs/`, `tasks/`, `CHANGELOG.md`, slash commands, `doc-updater` agent, `.claude/settings.json`, Impeccable skill).

## Backlog
Sourced from `docs/CODEBASE_AUDIT.md` (documentation only — not yet actioned):
- 🔴 Add missing migrations: `clients`, `products`, `countries`, `spaces`, `space_iqs`, `agentDetails`, `agentPrompt`, `client_customer` (+ `countries` seeder).
- 🔴 Fix broken route targets: `paymentHistoryy`→`paymentHistory`, missing `tokenCheck`/`logoutall`, `$this->error()` on `ResponseTrait`, missing `Appointment` model.
- 🟠 Document required secrets (`STRIPE_SECRET`, `WHAPI_MASTER_TOKEN`) in `.env.example` + `config/services.php`; stop reading via `env()`.
- 🟠 Tighten CORS (no wildcard origin), ensure `APP_DEBUG=false` in prod, add auth/OTP rate limiting.
- 🟡 Remove dead code/deps (`AuthhController`, `twilio/sdk`, `react-hot-toast`, dead `forgotPasswordApi`).
- 🟡 Add a test framework + CI for both repos.
