# Orvell Pulse — Architecture

> **Scope:** `orvell-pulse-backend/` (Laravel 12 API) + `orvell-pulse/` (Next.js 16 dashboard).
> **Date:** 2026-06-01 · **Basis:** static analysis of the two repositories only.
> **Rule applied:** factual — every statement below reflects code that actually exists. Where something the section asks about does **not** exist in the repo, that is stated explicitly rather than assumed. No application code was modified to produce this document.

---

## 1. System Overview

Orvell Pulse is a small business inventory & WhatsApp-commerce platform. A **Laravel 12 REST API** backend exposes JSON endpoints for authentication, stock/inventory, item categories, customers, orders, payments, and a "WhatsApp agent" provisioning feature; a separate **Next.js 16 admin dashboard** consumes that API to provide the management UI (Stocks, Item Categories, Customers, Orders, Profile/Agent settings, and auth screens). Authentication is token-based via Laravel Sanctum, with the frontend storing a bearer token in the browser. The backend also integrates Stripe (payments), the WHAPI.cloud WhatsApp gateway, an external OTP/email service, and Redis (for OTP storage). **Note:** the codebase is an early-stage prototype — several core tables referenced by the code have no migrations, so a number of flows do not run end-to-end on a clean install (see the companion `CODEBASE_AUDIT.md`).

---

## 2. High-Level Architecture

Two independently-deployed applications communicating over HTTPS/JSON. The frontend is a client-rendered SPA-style dashboard; the backend is a stateless token API.

```
                          ┌──────────────────────────────────────────────┐
                          │                Browser (user)                 │
                          │   localStorage: authToken, user, theme, …     │
                          └───────────────────────┬──────────────────────┘
                                                  │ HTTPS
                                                  ▼
        ┌─────────────────────────────────────────────────────────────────────┐
        │  FRONTEND — Next.js 16 (App Router, React 19, TS, Tailwind v4)        │
        │  Pages: / (Stocks), /customers, /orders, /settings (Item Cat),        │
        │         /profile, /auth/{signin,signup,forgot-password}               │
        │  Contexts: auth-provider, sidebar-provider, theme-provider            │
        │  HTTP layer:  fetch + lib/api.ts:getApiUrl()   (≈30 data calls)       │
        │               axios instance (APlutis.tsx)     (5 auth calls)         │
        └───────────────────────────────┬─────────────────────────────────────┘
                                         │  Bearer token in Authorization header
                                         │  Base URL: NEXT_PUBLIC_API_BASE_URL
                                         │           (default https://api.easycoders.in)
                                         │  Path prefix: /projects/orvell/public/api/...
                                         ▼
        ┌─────────────────────────────────────────────────────────────────────┐
        │  BACKEND — Laravel 12 REST API (PHP 8.2+)                             │
        │                                                                       │
        │  public/index.php → bootstrap/app.php → [HandleCors] →                │
        │  routes/api.php (/api prefix) → [auth:sanctum on protected group] →   │
        │  Controllers → FormRequest/validate → Services (DB::transaction) →    │
        │  Eloquent Models → API Resources / ResponseTrait → JSON               │
        │                                                                       │
        │  Controllers: Auth, Stock, ItemCategory, Customer, Order, Payment,    │
        │               Whatsapp(Agent), Country                                │
        └───┬───────────────┬───────────────┬───────────────┬──────────────────┘
            │               │               │               │
            ▼               ▼               ▼               ▼
     ┌────────────┐  ┌────────────┐  ┌──────────────┐ ┌───────────────────────┐
     │  Database  │  │   Redis    │  │  Stripe API  │ │  WHAPI.cloud (WhatsApp)│
     │ sqlite     │  │ OTP cache  │  │  payments    │ │  channel create / QR   │
     │ (MySQL in  │  │(Redis::    │  │ (Charge API) │ └───────────────────────┘
     │  prod)     │  │  setex)    │  └──────────────┘ ┌───────────────────────┐
     └────────────┘  └────────────┘                   │ SchoolEXL OTP/email API│
                                                       │ apiadmin.schoolexl.com │
                                                       └───────────────────────┘
```

**Component summary**

| Component | Role | Connects to |
|---|---|---|
| Next.js frontend | Admin dashboard UI; renders pages, holds auth token, calls the API | Backend API over HTTPS |
| Laravel backend | Stateless JSON API; business logic in service classes | Database, Redis, Stripe, WHAPI, SchoolEXL OTP API |
| Database | Persistence (Eloquent). `sqlite` by default; MySQL in production | — |
| Redis | Short-lived OTP storage (`Redis::setex`, 600s) during signup/reset | — |
| Stripe | Card charges in `PaymentController` | external |
| WHAPI.cloud | WhatsApp channel provisioning + QR login for the "agent" feature | external |
| SchoolEXL OTP API | Sends signup/reset OTP emails (hardcoded URL) | external |

There is **no API gateway, message broker, WebSocket server, or separate worker tier** — the two apps and the external HTTP services above are the entire runtime topology.

---

## 3. Directory Map

### Workspace root (`orvell pulse/`)
| Path | Purpose |
|---|---|
| `orvell-pulse-backend/` | Laravel 12 REST API. |
| `orvell-pulse/` | Next.js 16 admin dashboard. |
| `docs/` | Documentation (`CODEBASE_AUDIT.md`, `ARCHITECTURE.md`). |

### Backend `orvell-pulse-backend/` — top & second level
| Path | Purpose |
|---|---|
| `app/Http/Controllers/` | Request handlers (Auth via `API/AuthController`, Stock, ItemCategory, Customer, Order, Payment, Whatsapp, Country; + 9 empty scaffold controllers). |
| `app/Http/Controllers/API/` | `AuthController` (the live auth controller). |
| `app/Http/Request/` | Form Request validators (Stock/ItemCategory/Customer) — *non-standard singular namespace*. |
| `app/Http/Resources/` | API output transformers (Stock/ItemCategory/Customer/Order). |
| `app/Models/` | 25 Eloquent models (15 active, 10 empty scaffold stubs). |
| `app/Services/` | Business logic (Stock, ItemCategory, Customer, Order, Whatsapp). |
| `app/Traits/` | `ResponseTrait` (`success()`/`fail()`). |
| `app/Providers/` | `AppServiceProvider` (empty). |
| `bootstrap/` | `app.php` (routing/middleware/exceptions) + `cache/`. |
| `config/` | 16 config files (database, auth, sanctum, permission, cors, services, queue, cache, mail, l5-swagger, …). |
| `database/migrations/` | 19 migrations. |
| `database/seeders/` | `DatabaseSeeder` (default only). |
| `database/factories/` | `UserFactory` only. |
| `routes/` | `api.php` (all real endpoints), `web.php` (`/` welcome), `console.php` (`inspire` only). |
| `public/` | Web root: `index.php`, `.htaccess` (Apache rewrite). |
| `resources/` | Vite assets (`css/app.css`, `js/app.js`) + Blade views. |
| `storage/` | Runtime app/framework/logs + generated Swagger `api-docs/`. |
| `tests/` | `Unit/` + `Feature/` (skeleton tests only). |
| *(no `app/Jobs`, `app/Events`, `app/Listeners`, `app/Mail`, `app/Notifications`, `app/Console`, `app/Http/Middleware`)* | These standard dirs **do not exist** — see §7 and §11. |

### Frontend `orvell-pulse/` — top & second level
| Path | Purpose |
|---|---|
| `app/` | App Router pages + root `layout.tsx`, `loading.tsx`, `globals.css`. |
| `app/auth/` | `signin/`, `signup/`, `forgot-password/` route folders. |
| `app/{customers,orders,profile,settings}/` | Dashboard route folders (each a `page.tsx`). |
| `app/serviceApi/` | API layer: `APlutis.tsx` (axios instance + `handleError`), `allApi.tsx` (auth/country service fns). Not routable. |
| `components/` | `auth-wrapper`, `sidebar`, `main-content`. |
| `components/ui/` | shadcn primitives: `button`, `input`. |
| `context/` | `auth-provider`, `sidebar-provider`, `theme-provider`. |
| `lib/` | `api.ts` (`API_BASE_URL`, `getApiUrl()`), `utils.ts` (`cn()`, `getStoredUser()`). |
| `public/` | Default Next.js SVG assets. |
| *(root configs)* | `next.config.ts` (empty), `tsconfig.json`, `eslint.config.mjs`, `postcss.config.mjs`, `components.json`, `package.json`. |

---

## 4. Database Schema

ORM: **Eloquent**. Default connection `sqlite` (MySQL in production). Below are the **active** models with their tables, key fields, and relationships.

> ⚠️ **Schema-integrity caveat (factual):** only some of these tables actually have migrations. Tables **with** migrations: `users`, `orders`, `item_category`, `customer_management`, `payments`, the framework tables (`cache`, `jobs`, `sessions`, `password_reset_tokens`, `personal_access_tokens`), the spatie permission tables, and 10 empty scaffold tables. Tables **referenced by models/code but with NO migration**: `clients`, `customers`, `spaces`, `space_iqs`, `products` (used by `StockManagement`), `countries`, `agentDetails`, `agentPrompt`, `client_customer`, `otp_codes`. See `CODEBASE_AUDIT.md` §4.

### Active models

| Model | Table | Key fields | Relationships |
|---|---|---|---|
| `User` | `users` | name, email, password, country, currency, email_verified | — (none defined) |
| `Client` | `clients` | uuid, name, business_name, business_location, phone_number, email, password, security_question, security_answer | `belongsToMany(Customer)` via `client_customer`; `hasMany(Appointment)` ⚠ *(Appointment model missing)* |
| `Customer` | `customers` | uuid, name, whatsapp_number, email, address, country, onboarding_status, meta, zipcode | `belongsToMany(Client)`; `hasMany(Order)`; `hasMany(Appointment)` ⚠ |
| `ClientCustomer` | `client_customer` (pivot) | client_id, space_id, customer_id, first_interaction_at | `belongsTo(Customer)` |
| `Space` | `spaces` | uuid, client_id, name, chatbot_name, space_phone, is_active, category, country, currency, image, start_time, end_time | `belongsTo(Client)` |
| `space_iq` | `space_iqs` | uuid, space_id, prompt_content, attachments | — |
| `AgentDetails` | `agentDetails` | uuid, user_id, instance_id, ownerId, token, server, status, name, projectId, activeTill, _isPremium | `belongsTo(User)` |
| `agentPrompt` | `agentPrompt` | uuid, user_id, prompt_description | `belongsTo(User)` |
| `Order` | `orders` | uuid, order_no, space_id, customer_id, product_id, order_quantity, total_amount, currency, payment_status, payment_method, payment_reference, status, created_by, updated_by | `belongsTo(Space)`, `belongsTo(Customer)`, `belongsTo(StockManagement)` (product), `belongsTo(User)` ×2 (created/updated by) |
| `StockManagement` | **`products`** | uuid, client_id, space_id, name, slug, description, price, currency, unit, type, stock, sku, category, image, tags, is_featured, is_active | `belongsTo(Item_category)` (category → id) |
| `Item_category` | `item_category` | uuid, code, category_name, category_type, created_by, updated_by | — |
| `CustomerManagement` | `customer_management` | uuid, customer_code, name, phone_no, whatsapp_no, address_1, address_2, district, state, zip_code, created_by, updated_by | — |
| `Payment` | `payments` | customer_email, payment_id, amount, currency, status, response | — |
| `Country` | `countries` | uuid, country_code, country_name | — |
| `OtpCode` | `otp_codes` | email, otp (no timestamps) | — *(model never used in code)* |

### Scaffold-only models (empty stubs; tables migrated but no fields/relations/logic)
`Buyer`, `Bale`, `BaleBatch`, `Container`, `Reservation`, `Invoice`, `InvoiceItem`, `Release`, `DailySnapshot`, `WhatsappMessage` — a logistics/invoicing domain that is **not implemented** (no relationships, no foreign keys).

### Relationship map (wired parts only)
```
Client ─<>─ Customer            (M:N via client_customer pivot)
Client ──< Space ──< space_iq
Customer ──< Order
Order  ──> Space, Customer, StockManagement(product), User(created_by/updated_by)
StockManagement ──> Item_category
User ──< AgentDetails, agentPrompt
```
- Token ownership: `HasApiTokens` on `User`, `Client`, `AgentDetails`, `agentPrompt`, `Item_category`, `StockManagement`, `CustomerManagement`, `Order`.
- **No model uses spatie `HasRoles`** — there is no role/permission data model in use.
- UUID primary/secondary keys generated in model `boot()` hooks on 8+ models.

---

## 5. API Surface

All paths are prefixed with `/api` (applied by `withRouting(api: …)` in `bootstrap/app.php`). Authoritative source: `routes/api.php`.

### Auth (module: `API/AuthController`)
| Method | Path | Access |
|---|---|---|
| POST | `/api/signup` | public |
| POST | `/api/signin` | public |
| POST | `/api/signup/verify-otp` | public |
| POST | `/api/tokenCheck` | public ⚠ *(controller method missing)* |
| POST | `/api/password-reset` | public |
| POST | `/api/logout` | `auth:sanctum` |
| POST | `/api/logoutall` | `auth:sanctum` ⚠ *(controller method missing)* |

### Stocks (`StockManagementController`, prefix `Stocks`) — all `auth:sanctum`
| Method | Path |
|---|---|
| GET | `/api/Stocks/list` |
| POST | `/api/Stocks/add` |
| GET | `/api/Stocks/show/{uuid}` |
| PUT | `/api/Stocks/update/{uuid}` |
| DELETE | `/api/Stocks/delete/{uuid}` |

### Item Category (`Item_categoryController`, prefix `item-category`) — all `auth:sanctum`
`GET /api/item-category/list` · `POST /api/item-category/add` · `GET /api/item-category/show/{uuid}` · `PUT /api/item-category/update/{uuid}` · `DELETE /api/item-category/delete/{uuid}`

### Customer (`CustomerManagementController`, prefix `customer`) — all `auth:sanctum`
`GET /api/customer/list` · `POST /api/customer/add` · `GET /api/customer/show/{uuid}` · `PUT /api/customer/update/{uuid}` · `DELETE /api/customer/delete/{uuid}`

### Orders (`OrderController`, prefix `orders`) — all `auth:sanctum`
`GET /api/orders/list` · `GET /api/orders/show/{uuid}`

### Payment (`PaymentController`, prefix `payment`) — all `auth:sanctum`
| Method | Path | Note |
|---|---|---|
| POST | `/api/payment/create` | route name `payment` |
| GET | `/api/payment/history` | route name `payment.history` ⚠ *(controller method is `paymentHistoryy` — typo)* |

### Agent / WhatsApp (`WhatsappMessageController`, prefix `agent`) — all `auth:sanctum`
`POST /api/agent/initialiseAgent` · `POST /api/agent/storeAgentPrompt`

### Country (`CountryController`)
| Method | Path | Access | Note |
|---|---|---|---|
| GET | `/api/Country` | public | ⚠ 500 — no `countries` table |

### Non-API
| Method | Path | Handler |
|---|---|---|
| GET | `/` | `web.php` → `welcome` view |
| GET | `/up` | framework health check |

`routes/console.php` defines only the default `inspire` command (no scheduled tasks).

---

## 6. Authentication & Authorization

### Mechanism
- **Laravel Sanctum** personal-access-tokens (bearer), not session cookies. `bootstrap/app.php` deliberately omits `EnsureFrontendRequestsAreStateful` (comment notes it caused CSRF enforcement), so the API runs in pure token mode.
- Tokens are minted in `API/AuthController@login` via `$client->createToken('api_token', [], now()->addDays(7))` → **7-day expiry**, persisted in the `personal_access_tokens` table.
- The authenticated principal is the **`Client`** model (not `User`). Note `config/auth.php`'s default `web` guard/provider still points at `App\Models\User`; token resolution works regardless because Sanctum resolves the token's owning model directly.

### Where credentials/tokens live
| Layer | Storage |
|---|---|
| Backend issue/verify | `personal_access_tokens` table (Sanctum). Passwords hashed via bcrypt on `Client`. |
| Backend OTP (signup/reset) | **Redis** — `Redis::setex("otp:orvell:signup:{email}", 600, $otp)` (10-min TTL). OTP email delivery via the external SchoolEXL HTTP API. |
| Frontend | Browser **`localStorage`**: `authToken` (bearer) + `user` (profile JSON). |
| Frontend request auth | Two paths: the axios request interceptor (`app/serviceApi/APlutis.tsx`) injects `Authorization: Bearer <authToken>`; the `fetch`-based pages attach the header manually. |
| Frontend session validation | `context/auth-provider.tsx` on mount calls `POST /tokenCheck`; 401/403 → clears token & logs out; other errors → keeps token. |

### Authorization
- **Route protection:** a single `Route::middleware('auth:sanctum')` group gates all non-auth/non-public endpoints. There is **no role/permission enforcement** — `spatie/laravel-permission` is installed and its tables migrated, but no model uses `HasRoles` and no `role`/`permission` middleware is applied anywhere.
- **Frontend gating:** `components/auth-wrapper.tsx` hard-redirects only `/profile` (its `PROTECTED_ROUTES` list); authenticated users are redirected away from `/auth/*`; other pages render but show a "sign in" prompt and skip data fetching when unauthenticated. This is UI gating only, not server-enforced authorization.

---

## 7. Background Jobs / Queues

**No application-level background processing exists.** Verified by inspection:
- `config/queue.php` default is the **`database`** driver, and the `jobs`/`job_batches`/`failed_jobs` tables are migrated.
- The `composer dev` script launches `php artisan queue:listen --tries=1` alongside the dev server.
- **However, there are no `Job` classes** (`app/Jobs` does not exist) and **nothing is ever dispatched** — a grep across `app/` for `ShouldQueue`, `dispatch(`, `Bus::`, `Queue::`, `->onQueue`, `Mail::`, and `Notification::` returns **zero matches**.

➡️ The queue is configured but **effectively unused**: all work (Stripe charges, WHAPI calls, OTP send) happens **synchronously** inside the request lifecycle. No mailables, no notifications, no queued listeners, and no scheduled/cron tasks (`routes/console.php` only registers `inspire`).

---

## 8. Third-Party Integrations

| Service | Used by (module) | Mechanism | Credentials / config required |
|---|---|---|---|
| **Stripe** (card payments) | `PaymentController` | `stripe/stripe-php`, `Stripe::setApiKey(env('STRIPE_SECRET'))` → `Charge::create` (INR) | `STRIPE_SECRET` — read via raw `env()`; **not** in `.env.example`/`services.php` |
| **WHAPI.cloud** (WhatsApp) | `WhatsappMessageService` (agent init / QR / health) | Guzzle / `Http` to `manager.whapi.cloud`, `gate.whapi.cloud` | `WHAPI_MASTER_TOKEN` — read via raw `env()`; **not** in `.env.example`. Hardcoded `projectId`. |
| **SchoolEXL OTP/email API** | `API/AuthController` (signup & password-reset OTP) | `Http::post('https://apiadmin.schoolexl.com/index.php/api/v2/auth/send-otp', …)` | none (endpoint hardcoded) |
| **Redis** | `API/AuthController` (OTP cache) | phpredis `Redis::setex` | `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` |
| **Database** | all persistence | Eloquent | `DB_*` (sqlite default; MySQL in prod) |
| **Twilio** | — | `twilio/sdk` installed | **unused** (zero code references) |
| **AWS S3 / SES / SQS / DynamoDB, Slack, Postmark, Resend** | — | config stubs only | **unused** (no code; S3 also lacks the flysystem-s3 adapter) |
| **Frontend → Backend API** | entire frontend | axios + `fetch` | `NEXT_PUBLIC_API_BASE_URL` (default `https://api.easycoders.in`) |

There is **no LLM/AI integration** despite the "agent" naming — `space_iq`/`agentPrompt` only store prompt text; no inference endpoint is called.

---

## 9. Deployment Architecture

> Factual note: **neither repo contains deployment automation** — no `Dockerfile`/`docker-compose`, no `.github/workflows`, no `vercel.json`/`netlify.toml`, no Terraform/Ansible/IaC, and no deploy scripts. Deployment is therefore **manual**. The following reflects the only deployment evidence present in the code.

### Backend
- **Public host:** the frontend's default API base is `https://api.easycoders.in`, and all endpoints are called under the path `…/projects/orvell/public/api/…`. This path layout (`projects/<app>/public`) is characteristic of **shared/cPanel-style hosting** where the Laravel `public/` directory is served from a subpath.
- **Web server:** Apache — `public/.htaccess` (Laravel's standard rewrite rules) is present.
- **Database in production:** MySQL (the committed default is sqlite, but `config/database.php` includes a MySQL connection and the artifact in §12 shows a running MySQL server).
- **Process model:** standard PHP request handling (`php artisan serve` for local; Apache + PHP-FPM/mod_php in prod). The `composer dev` helper runs `serve` + `queue:listen` + `pail` + Vite concurrently for local development only.

### Frontend
- **Build/run:** Next.js standard pipeline — `next build` then `next start` (or `next dev` locally). No hosting target is configured in-repo (no Vercel/Netlify/Docker config), so the deployment host is **not specified by the codebase**. `.gitignore` references `.vercel` and the default `public/vercel.svg` exists, but no actual Vercel project config is committed.

### Topology (as deployed, inferred from runtime references only)
```
[Users] ──HTTPS──> [Next.js frontend host (unspecified in repo)]
                        │
                        └──HTTPS──> https://api.easycoders.in/projects/orvell/public/api
                                         (Apache + PHP, Laravel)
                                                 │
                                                 ├── MySQL
                                                 ├── Redis
                                                 └── outbound HTTPS → Stripe, WHAPI, SchoolEXL
```

---

## 10. Key Environment Variables

### Backend (`orvell-pulse-backend/.env`)
| Group | Variables |
|---|---|
| **App** | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `BCRYPT_ROUNDS`, `APP_MAINTENANCE_DRIVER` |
| **Logging** | `LOG_CHANNEL`, `LOG_STACK`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` |
| **Database** | `DB_CONNECTION` (default `sqlite`); for MySQL: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| **Session** | `SESSION_DRIVER` (`database`), `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` |
| **Cache / Queue / Broadcast / FS** | `CACHE_STORE` (`database`), `QUEUE_CONNECTION` (`database`), `BROADCAST_CONNECTION` (`log`), `FILESYSTEM_DISK` |
| **Redis** | `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` |
| **Mail** | `MAIL_MAILER` (`log`), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| **AWS** (config stubs, unused) | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT` |
| **Vite** | `VITE_APP_NAME` |
| **⚠ Required by code but NOT in `.env.example`** | `STRIPE_SECRET` (Stripe), `WHAPI_MASTER_TOKEN` (WhatsApp) — both read via raw `env()`; must be added manually or those features fail. |

### Frontend (`orvell-pulse/.env.local`)
| Group | Variable |
|---|---|
| **API base URL** | `NEXT_PUBLIC_API_BASE_URL` — default fallback `https://api.easycoders.in` (referenced in `APlutis.tsx`, `lib/api.ts`; one hardcoded copy in `profile/page.tsx`). This is the **only** frontend env var. |

---

## 11. Real-Time / Event Flows

**None exist.** Verified by inspection:
- **No broadcasting / WebSockets:** `BROADCAST_CONNECTION` is `log`; there are no events (`app/Events` absent), no `ShouldBroadcast`, no `broadcast()`/`event()` calls, and no Pusher/Reverb/Soketi/Laravel-Echo packages or config. Grep for `broadcast`, `ShouldBroadcast`, `event(`, `Event::` in `app/` returns zero matches.
- **No SSE / long-polling / pub-sub** in either app.
- **No realtime client libs** on the frontend (no `socket.io-client`, `pusher-js`, `@supabase/realtime`, EventSource usage, etc.).
- The closest thing to "live" behavior is the **WhatsApp agent QR retrieval** in `app/profile/page.tsx`, which is a **one-shot HTTPS request** to the backend (`agent/initialiseAgent`) that returns a QR URL/blob — request/response, not a streaming channel.

➡️ All client↔server communication is **synchronous request/response over HTTPS**.

---

## 12. Server Access

> Factual note: **the repositories contain no server-access configuration** — no SSH config or keys, no deploy user definitions, no firewall/security-group rules, no bastion/VPN config, and no infrastructure-as-code. The items below are the **only** access-relevant facts evidenced in the code, plus an explicit list of what is absent.

### Evidenced facts
| Item | Value | Source |
|---|---|---|
| Production API domain | `https://api.easycoders.in` (Laravel served under `…/projects/orvell/public/`) | frontend default base URL (`APlutis.tsx`, `lib/api.ts`, `profile/page.tsx`) |
| Web server | Apache | `public/.htaccess` present |
| Production DB engine | MySQL Community Server (active/running as of 2025-12-12) | the stray file `orvell-pulse-backend/ql -u orvell_user -p` (captured `systemctl status mysql` output) |
| Server hostname (from that artifact) | `ubuntu-s-2vcpu-8gb-amd-blr1-01` (an Ubuntu host; name pattern suggests a 2 vCPU / 8 GB AMD instance in a "blr1"/Bangalore region) | same stray file |
| Database username (leaked) | `orvell_user` | the **filename** of that stray artifact (`ql -u orvell_user -p` = tail of a mistyped `mysql -u orvell_user -p`) |

### ⚠ Security note
The file `orvell-pulse-backend/ql -u orvell_user -p` is an accidental commit that **leaks the production server hostname and a database username**. It is not git-ignored and should be deleted from the repository (and ideally purged from history). No password is exposed in it.

### Explicitly NOT present in the repos
- ❌ SSH configuration, host entries, or key material.
- ❌ Deployment user / sudo / RBAC definitions.
- ❌ Firewall rules, cloud security groups, or network ACLs.
- ❌ Server IP address(es) — only the domain `api.easycoders.in` and the hostname above are referenced; no IP is in the code.
- ❌ Bastion/jump-host, VPN, or any access-control policy.
- ❌ Infrastructure-as-code (Terraform/Ansible/CloudFormation) or container/orchestration manifests.

Anyone needing real server-access details must obtain them from the hosting provider / operator out-of-band; they are **not derivable from this codebase**.

---

*End of architecture document. Companion: `docs/CODEBASE_AUDIT.md`.*
