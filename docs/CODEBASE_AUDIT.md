# Orvell Pulse — Codebase Audit

> **Scope:** Both repositories that make up the Orvell Pulse product:
> - **Backend** — `orvell-pulse-backend/` (Laravel 12 REST API, PHP 8.2+)
> - **Frontend** — `orvell-pulse/` (Next.js 16 App Router, React 19, TypeScript, Tailwind v4)
>
> **Audit date:** 2026-06-01
> **Method:** Read-only static investigation by parallel subagents. No source files were created, modified, or deleted (this `docs/` folder is the only addition).

---

## Executive Summary

Orvell Pulse is a **WhatsApp-commerce / inventory-management** platform: a Laravel API backend plus a Next.js admin dashboard (Stocks, Item Categories, Customers, Orders, Profile/Agent settings, plus auth).

**Overall maturity: early-stage prototype.** The two repos are individually runnable, but the backend has substantial **model ↔ migration ↔ database schema drift** that prevents the core flows (signup, stocks) from working on a clean install. Highlights:

| Area | Assessment |
|---|---|
| Architecture | ✅ Clean separation: stateless token API + SPA-style dashboard |
| Backend schema integrity | ❌ ~10 core tables referenced by code have **no migration** (clients, products, countries, spaces, …) |
| Routing correctness | ⚠️ Several routes point at **non-existent / misnamed controller methods** |
| Code health | ⚠️ Dead files, duplicated logic, two parallel HTTP stacks (FE), two toast libs (FE), inconsistent response envelopes (BE) |
| Tests | ❌ Backend = skeleton only; Frontend = none at all |
| CI/CD | ❌ None in either repo |
| Security hygiene | ⚠️ Wildcard CORS, `APP_DEBUG=true`, secrets read via raw `env()`, credentials/OTP logged to console |

> ⚠️ **Action item carried over from setup:** the GitHub PAT shared in chat is exposed — revoke/regenerate it.

---

## 1. Top-Level Directory Structure

### Workspace root (`orvell pulse/`)
| Folder | Description |
|---|---|
| `orvell-pulse-backend/` | Laravel 12 REST API. |
| `orvell-pulse/` | Next.js 16 admin dashboard frontend. |
| `docs/` | This audit (newly created). |

### Backend — `orvell-pulse-backend/`
| Path | Description |
|---|---|
| `app/` | Application code: `Http/Controllers`, `Http/Controllers/API`, `Http/Request` *(non-standard singular)*, `Http/Resources`, `Models`, `Services`, `Traits`, `Providers`. |
| `bootstrap/` | Framework bootstrap — `app.php` (Laravel 12 streamlined routing/middleware/exceptions) + compiled `cache/`. |
| `config/` | 16 config files (database, auth, sanctum, permission, services, cors, l5-swagger, queue, cache, mail, …). |
| `database/` | `migrations/` (19), `seeders/` (default only), `factories/` (UserFactory only), and a populated `database.sqlite`. |
| `public/` | Web root: `index.php`, `.htaccess` (Apache), `favicon.ico`, `robots.txt`. |
| `resources/` | Vite-managed `css/app.css`, `js/app.js`, Blade views. |
| `routes/` | `api.php` (all real endpoints), `web.php` (`/` welcome), `console.php` (default `inspire`). |
| `storage/` | Runtime `app/`, `framework/`, `logs/`, generated `api-docs/` (Swagger). |
| `tests/` | `Feature/`, `Unit/`, `TestCase.php` — skeleton only. |
| `composer.json/.lock`, `package.json`, `vite.config.js`, `phpunit.xml`, `artisan` | Manifests / tooling. |

**Non-standard / stray items flagged:**
- 🗑️ **`ql -u orvell_user -p`** (repo root, 742 B) — captured stdout of `systemctl status mysql` from the production droplet (`ubuntu-s-2vcpu-8gb-amd-blr1-01`, 2025-12-12). A mistyped-command artifact. **Not git-ignored** → would be committed. Leaks server hostname + DB username `orvell_user`. Should be deleted.
- `config/passport.php` — orphaned (Laravel Passport is **not** a dependency; auth uses Sanctum).
- `app/Http/Controllers/AuthhController.php` — misspelled ("Authh") **duplicate** of the real `API/AuthController.php`; unrouted dead file.
- `app/Http/Request/` — Form Requests live under the **singular** `App\Http\Request` namespace (Laravel convention is plural `Requests`). No `app/Http/Middleware/` exists.
- Non-PSR model names: `app/Models/agentPrompt.php`, `space_iq.php`, plus snake_case `Item_category*`.
- `database/database.sqlite` (~232–237 KB) present in the tree.

### Frontend — `orvell-pulse/`
| Path | Description |
|---|---|
| `app/` | App Router tree (pages, layout, loading, globals.css) + `serviceApi/` (API layer, not routable). |
| `components/` | `auth-wrapper`, `sidebar`, `main-content`, and `ui/` (shadcn `button`, `input`). |
| `context/` | React context providers: `auth-provider`, `sidebar-provider`, `theme-provider`. |
| `lib/` | `api.ts` (base URL + `getApiUrl()`), `utils.ts` (`cn()`, `getStoredUser()`). |
| `public/` | Default Next SVGs only. |
| `next.config.ts` *(empty)*, `tsconfig.json`, `eslint.config.mjs`, `postcss.config.mjs`, `components.json`, `package.json` | Config/manifests. |

**App Router tree:**
```
app/
├── layout.tsx            (only layout — providers + sonner Toaster)
├── loading.tsx           (global skeleton)
├── globals.css           (Tailwind v4 + oklch theme tokens)
├── page.tsx              →  /                     (STOCKS management)
├── auth/
│   ├── signin/page.tsx   →  /auth/signin
│   ├── signup/page.tsx   →  /auth/signup          (+ OTP step, country select)
│   └── forgot-password/page.tsx → /auth/forgot-password (3-step reset)
├── customers/page.tsx    →  /customers            (Customers CRUD)
├── orders/page.tsx       →  /orders               (read-only list)
├── profile/page.tsx      →  /profile              (profile + WhatsApp agent/QR)
├── settings/page.tsx     →  /settings             (ITEM CATEGORY CRUD)
└── serviceApi/           (APlutis.tsx, allApi.tsx — service layer, no page.tsx)
```
No nested layouts, route groups, `error.tsx`, or `not-found.tsx`.

---

## 2. Tech Stack

### Backend (Laravel)
- **Language/Framework:** PHP `^8.2` · Laravel `^12.0` (resolved **v12.41.1**) · Eloquent ORM · stateless REST under `/api`.
- **Auth:** **Laravel Sanctum** `^4.2` (Bearer personal-access-tokens, 7-day expiry). `User`, `Client`, and several models use `HasApiTokens`.
- **Default infra (from `.env`):** DB `sqlite` (MySQL used in prod), Queue `database`, Cache `database`, Session `database`, Mail `log`.
- **API docs:** `darkaonline/l5-swagger` `^8.5` + `zircote/swagger-php` (UI at `/api/documentation`).

| Composer package (prod) | Resolved | Purpose | Status |
|---|---|---|---|
| `laravel/framework` | 12.41.1 | Core framework | ✅ used |
| `laravel/sanctum` | 4.2.1 | Token API auth | ✅ used |
| `laravel/tinker` | 2.10.2 | REPL | ✅ dev tool |
| `spatie/laravel-permission` | 6.23.0 | Roles & permissions | ⚠️ installed + migrated, **no `HasRoles` anywhere** → dormant |
| `darkaonline/l5-swagger` | 8.6.5 | OpenAPI UI/generator | ✅ used (annotations) |
| `zircote/swagger-php` | 4.11.1 | Annotation parser | ✅ used |
| `stripe/stripe-php` | 18.2.0 | Payments | ⚠️ used in `PaymentController` but `STRIPE_SECRET` undefined; legacy `Charge` API |
| `twilio/sdk` | 8.9.0 | SMS/voice/WhatsApp | ❌ **zero usages** — dead dependency |
| `guzzlehttp/guzzle` | 7.10.0 | HTTP client | ✅ used (WHAPI / OTP HTTP) |

Dev: `phpunit/phpunit 11.5`, `fakerphp/faker`, `mockery`, `nunomaduro/collision`, `laravel/pint`, `laravel/sail` (no Docker files generated), `laravel/pail`.

### Frontend (Next.js)
- **Next.js** `^16.0.7` (installed 16.0.10, App Router, RSC) · **React** `^19.2.1` · **TypeScript** `^5` (strict) · **Tailwind v4** (CSS-first, no `tailwind.config`; configured in `globals.css`).
- **UI:** shadcn/ui (`components.json`, style "new-york", lucide icons) — only `button` + `input` generated so far.
- **State:** React Context only (auth / sidebar / theme). No Redux/Zustand/React-Query. Server data fetched per-page via `useEffect` + `fetch`. Persistent bits in `localStorage` (`authToken`, `user`, `sidebar-collapsed`, `dark-mode`).

| Dependency | Version | Purpose | Status |
|---|---|---|---|
| `axios` | ^1.13.2 | HTTP client (auth service calls only) | ✅ partial |
| `formik` | ^2.4.9 | Forms (signin/signup only) | ✅ partial |
| `lucide-react` | ^0.555.0 | Icons | ✅ used |
| `sonner` | ^2.0.7 | Toasts (global) | ✅ primary |
| `react-hot-toast` | ^2.6.0 | Toasts (one page) | ❌ **redundant** — remove |
| `@radix-ui/react-slot` | ^1.2.4 | `asChild` for Button | ✅ used |
| `class-variance-authority` | ^0.7.1 | Button variants | ✅ used |
| `clsx` + `tailwind-merge` | ^2.1.1 / ^3.4.0 | `cn()` helper | ✅ used |

---

## 3. Data Flow

### Backend request lifecycle
```
public/index.php  →  bootstrap/app.php  →  [HandleCors global middleware]
   →  routes/api.php (/api prefix auto-applied)  →  [auth:sanctum on protected group]
   →  FormRequest / inline validate  →  Controller  →  Service (DB::transaction)
   →  Eloquent Model  →  API Resource / ResponseTrait  →  JSON response
```
- **Entry:** `public/index.php` → `$app->handleRequest(Request::capture())`.
- **Bootstrap:** `bootstrap/app.php` uses Laravel 12's streamlined config. `withRouting(api: routes/api.php, health: '/up')` applies the `/api` prefix + `api` group. Only **one** global middleware is added: `HandleCors`. `EnsureFrontendRequestsAreStateful` is **deliberately omitted** (comment notes it caused CSRF enforcement) → pure bearer-token mode.
- **Middleware:** No custom middleware classes exist (`app/Http/Middleware/` absent). Protected routes use the `auth:sanctum` alias.
- **Validation:** Mixed — FormRequest classes (`app/Http/Request/`) for Stock/ItemCategory/Customer; inline `$request->validate()` for Auth/Payment/Agent.
- **Business logic:** 5 service classes (`app/Services/`) wrap `DB::transaction`; Auth/Payment/Country talk to Eloquent directly.
- **Response shape is inconsistent** (see §6) — no single envelope.

**Traced example A — `POST /api/signin`:**
`index.php → bootstrap/app.php → routes/api.php:14 → API/AuthController@login → Client::where(email)->first() → Hash::check → $client->createToken(...) → response()->json({status,message,user,token})`.

**Traced example B — `POST /api/Stocks/add` (auth):**
`index.php → bootstrap/app.php → routes/api.php (auth:sanctum → Stocks group) → Http/Request/StockManagementRequest (validate) → StockManagementController@store → StockManagementService::create (DB::transaction, resolves Space, sets client_id/created_by) → StockManagement model (boot: uuid + auto-SKU) → StockManagementResource → ResponseTrait::success (201)`.

### Frontend → Backend
There are **two parallel HTTP mechanisms** (a notable smell):

1. **`fetch` + `lib/api.ts` `getApiUrl()` — the PRIMARY path** (~30 call sites): every data page (`/`, `/customers`, `/orders`, `/settings`, `/profile`), plus `auth-provider` and `sidebar`. Each manually attaches the `Authorization: Bearer` header.
2. **Axios instance — `app/serviceApi/APlutis.tsx`** — used **only** by the 5 functions in `allApi.tsx` (signup/signin/forgot/reset/country). Has request interceptor (injects token) + response interceptor (logs 401; redirect is commented out) + `handleError()` + the shared `ApiResponse<T>` type.

Both default `baseURL` to `https://api.easycoders.in` and prefix endpoints with `projects/orvell/public/api/...`. Because most calls use `fetch`, the axios interceptors' benefits don't apply app-wide.

**Traced flow — Sign-in:** `auth/signin/page.tsx` (Formik submit) → `signinApi()` → axios `API.post(".../signin")` → on success `setToken()` (auth context) + `localStorage` → `router.push("/")` → `auth-wrapper.tsx` renders sidebar + Stocks page.

**Auth context (`context/auth-provider.tsx`):** on mount reads `localStorage.authToken`, validates via `POST .../tokenCheck`; 401/403 → clear & logout; other errors → keep token (resilient to backend downtime).

---

## 4. Database Models, Tables & Relationships

### Model inventory (`app/Models/`, 25 files)
**Active, fleshed-out models:** `User`, `Client`, `Customer`, `ClientCustomer`, `Space`, `space_iq`, `AgentDetails`, `agentPrompt`, `Order`, `StockManagement` (table `products`), `Item_category`, `CustomerManagement`, `Payment`, `Country`, `OtpCode`.

**Empty scaffold stubs (no fillable, no relations):** `Buyer`, `Bale`, `BaleBatch`, `Container`, `Reservation`, `Invoice`, `InvoiceItem`, `Release`, `DailySnapshot`, `WhatsappMessage` — an entire logistics/invoicing domain that is **scaffold-only**.

### Relationship map (the wired parts)
```
Client (1)─┬─< Space ──< space_iq
           ├─<>─ Customer            (belongsToMany via client_customer pivot)
           └─< Appointment   ⚠ MODEL DOES NOT EXIST → runtime error if called

Customer (1)─┬─< Order
             ├─<>─ Client            (belongsToMany)
             └─< Appointment   ⚠ MODEL DOES NOT EXIST

Order ──> Space (space_id) , Customer (customer_id) , StockManagement (product_id) , User (created_by/updated_by)
StockManagement ──> Item_category (category → id)
User (1)─< AgentDetails , agentPrompt
```
- `HasApiTokens`: `User`, `Client`, `AgentDetails`, `agentPrompt`, `Item_category`, `StockManagement`, `CustomerManagement`, `Order`.
- `HasRoles` (spatie): **none** — permissions package is dormant.
- UUID boot hook duplicated across 8+ models; `created_by/updated_by` stamping duplicated across 4.

### ❌ CRITICAL — schema drift (migration ↔ model ↔ DB)
19 migrations exist; verified against `database.sqlite`. **Tables referenced by code that have NO migration and are absent from the DB:**

| Missing table | Used by | Impact |
|---|---|---|
| `clients` | `Client` model, both auth controllers | **Signup/login fail** |
| `products` | `StockManagement` (entire Stocks API) | **Stocks module non-functional** |
| `countries` | `Country` model, `GET /api/Country` | **500 on country list** (confirmed during setup) |
| `spaces`, `space_iqs` | created during signup & stock create | flow breaks |
| `agentDetails`, `agentPrompt` | WhatsApp agent flow | flow breaks |
| `client_customer` (pivot) | Client/Customer M:N | relation breaks |
| `otp_codes` | `OtpCode` (never actually used) | dead |
| `email_otps`, `password_resets` | raw `DB::table()` in `AuthhController` (dead file) | n/a |

**Tables migrated but model is an empty stub (dead schema):** `buyers`, `bales`, `bale_batches`, `containers`, `reservations`, `invoices`, `invoice_items`, `releases`, `daily_snapshots`, `whatsapp_messages`.

**Column-level mismatches:**
- **`orders`:** migration has `order_no, customer_name, total_amount, status, created_by, updated_by`; the `Order` model + `OrderService` expect `space_id, customer_id, product_id, order_quantity, currency, payment_*` (absent). `OrderResource` outputs `order_amount` (exists nowhere → always `0.0`).
- **`users`:** model fillable adds `country, currency, email_verified` — none exist in the migration.
- **`products`/Stocks:** `StockManagementRequest` validates `item_name, available_unit, original_price, item_category_id`; model `$fillable` is `name, stock, price, category` → validated fields are silently dropped on create (mass-assignment mismatch) — *even if the `products` table existed.*

> **Direct answer to "is there a `countries` migration/seeder?":** No — neither a migration nor a seeder ships for `countries`. This is why `/api/Country` 500s on a fresh DB.

---

## 5. API Routes / Endpoints (grouped by module)

All paths carry the `/api` prefix. Source of truth: `routes/api.php` (Swagger annotations are unreliable — they describe different paths).

### Public (no middleware)
| Method | Path | Controller@method | Note |
|---|---|---|---|
| POST | `/api/signup` | `API/AuthController@register` | |
| POST | `/api/signin` | `API/AuthController@login` | |
| POST | `/api/signup/verify-otp` | `API/AuthController@register` | same method, OTP branch |
| POST | `/api/tokenCheck` | `API/AuthController@tokenCheck` | ⚠️ **method does not exist** |
| POST | `/api/password-reset` | `API/AuthController@passwordResetFlow` | |
| GET | `/api/Country` | `CountryController@countries` | ⚠️ 500 (no `countries` table) |

### Auth-protected (`auth:sanctum`)
**Auth:** `POST /api/logout` → `AuthController@logout`; `POST /api/logoutall` → `AuthController@logoutall` ⚠️ **method does not exist**.

**Stocks** (`/api/Stocks/*`) → `StockManagementController`: `list` (index), `add` (store), `show/{uuid}`, `update/{uuid}`, `delete/{uuid}`.
**Item Category** (`/api/item-category/*`) → `Item_categoryController`: `list`, `add`, `show/{uuid}`, `update/{uuid}`, `delete/{uuid}`.
**Customer** (`/api/customer/*`) → `CustomerManagementController`: `list`, `add`, `show/{uuid}`, `update/{uuid}`, `delete/{uuid}`.
**Orders** (`/api/orders/*`) → `OrderController`: `list` (index), `show/{uuid}`.
**Payment** (`/api/payment/*`) → `PaymentController`: `create` (payment); `history` → `paymentHistory` ⚠️ **controller defines `paymentHistoryy` (typo) → unresolvable**.
**Agent** (`/api/agent/*`) → `WhatsappMessageController`: `initialiseAgent`, `storeAgentPrompt`.

**Other:** `GET /` (web.php → welcome view) · `GET /up` (health) · `console.php` only defines `inspire`.
**Commented-out:** `password/change` (api.php:17) and an old `/login` group (api.php:62–66).

---

## 6. Shared Utilities & Common Patterns

### Backend
- **`app/Traits/ResponseTrait.php`** — `success()` / `fail()`. ⚠️ Multiple controllers call `$this->error(...)` which **doesn't exist** (`StockManagementController` 404 branches → `BadMethodCallException`).
- **Inconsistent response envelopes:** `{status,message,data}` (trait) vs `{message,data}` vs `{success,data,meta}` vs `{status:200,...}`. No unified contract.
- **Service layer** (`app/Services/`) — thin-controller pattern, `DB::transaction`; only the "management" modules follow it.
- **API Resources** (`app/Http/Resources/`) — 4 exist; `CustomerManagementResource` mis-maps fields; `OrderResource` references a non-existent column.
- **Duplicated boot logic** — UUID generation + `created_by/updated_by` stamping copy-pasted across many models (candidate for a `HasUuid` trait + observer).
- **Base `Controller`** is empty except for global Swagger annotations (doesn't pull in `AuthorizesRequests`/`ValidatesRequests`).

### Frontend
- **`lib/utils.ts`** — `cn()` (clsx + tailwind-merge), `getStoredUser()`. Used consistently.
- **`ApiResponse<T>` + `handleError()`** (`APlutis.tsx`) — solid centralized error normalization, but only reaches axios calls.
- **Service-function pattern** (`allApi.tsx`) — clean and consistent.
- **Context providers** — theme/auth mounted in `layout.tsx`; **sidebar provider mounted separately** inside `auth-wrapper.tsx` (diverges).
- **Inconsistencies:** two HTTP stacks (axios vs fetch); two form strategies (Formik vs manual `useState`); duplicated skeleton markup (`auth-wrapper` vs `loading.tsx`, table skeletons copy-pasted); auth-fetch boilerplate repeated ~15×.

---

## 7. Test Setup

### Backend
- **Framework:** PHPUnit 11.5 (not Pest, despite the plugin being allow-listed).
- **Config:** `phpunit.xml` — `Unit` + `Feature` suites; test env forces `sqlite :memory:`, `BCRYPT_ROUNDS=4`, array drivers, `QUEUE_CONNECTION=sync`.
- **Reality:** only the two stock `ExampleTest.php` files (assert `true`, hit `GET /`). **Zero tests for models/services/controllers/endpoints.** `RefreshDatabase` is commented out.
- **Run:** `composer test` (→ `config:clear` + `php artisan test`) or `php artisan test` or `./vendor/bin/phpunit`.

### Frontend
- **None.** No Jest/Vitest/Playwright/Cypress/Testing-Library, no `*.test.*`/`*.spec.*` files, no `test` script. Testing is completely absent.

---

## 8. Build / Deploy & CI/CD

### Backend
- **Composer scripts:** `setup` (install → env → key → migrate → npm build), `dev` (`concurrently`: `serve` + `queue:listen` + `pail` + `vite`), `test`.
- **Assets:** Vite 7 + `laravel-vite-plugin` + `@tailwindcss/vite` (`vite.config.js`); `package.json` `build`/`dev`.
- **Hosting:** manual on an Ubuntu droplet running MySQL (per the stray status file); Apache (`public/.htaccess`).

### Frontend
- **npm scripts:** `dev` (`next dev`), `build` (`next build`), `start` (`next start`), `lint` (`eslint`). ESLint flat config extends `eslint-config-next` (core-web-vitals + TS).

### CI/CD — ❌ NONE in either repo
No `.github/workflows`, no `Dockerfile`/`docker-compose` (despite `laravel/sail`), no `vercel.json`/`netlify.toml`, no deploy scripts. The README CI badge points at upstream Laravel, not this project.

---

## 9. Environment Variables & Config Files

### Backend (`.env.example`)
Groups: **App** (`APP_NAME/ENV/KEY/DEBUG/URL/LOCALE`, `BCRYPT_ROUNDS`), **Logging**, **Database** (`DB_CONNECTION=sqlite`; MySQL keys commented), **Session/Cache/Queue** (all `database`), **Redis**, **Mail** (`log`), **AWS** (S3/SES placeholders), **Vite**.

Key configs: `database.php` (5 connections, default sqlite), `auth.php` (guard `web` → `User`), `sanctum.php` (no token expiry), `permission.php` (dormant), `services.php` (postmark/resend/ses/slack — none used; **no Stripe/Twilio entries**), `cors.php` (**`allowed_origins: ['*']`**), `l5-swagger.php` ("Orvell Backend API Documentation").

> ⚠️ **Undocumented required secrets:** `STRIPE_SECRET` (Stripe) and `WHAPI_MASTER_TOKEN` (WhatsApp) are read via raw `env()` but are **absent from `.env.example` and `services.php`** → payments & WhatsApp silently fail on a clean checkout. Using `env()` outside config also breaks under `config:cache`.
> ⚠️ **S3 disk configured but `league/flysystem-aws-s3-v3` is not installed** → non-functional.

### Frontend
- **Only env var:** `NEXT_PUBLIC_API_BASE_URL` (default `https://api.easycoders.in`), duplicated in `APlutis.tsx`, `lib/api.ts`, and hardcoded once in `profile/page.tsx:69`.
- **Configs:** `next.config.ts` (empty), `tsconfig.json` (strict, `@/*` alias), `eslint.config.mjs`, `postcss.config.mjs` (Tailwind v4), `components.json` (shadcn), `globals.css` (oklch theme; the `.dark` block is **duplicated verbatim**).

---

## 10. Third-Party Integrations

### Backend
| Integration | Mechanism | Where | Status |
|---|---|---|---|
| **Stripe** (payments) | `stripe/stripe-php` | `PaymentController` (`Charge::create`, INR) | ⚠️ `STRIPE_SECRET` undefined; legacy API |
| **WHAPI.cloud** (WhatsApp) | Guzzle/`Http` | `WhatsappMessageService` (channel create, QR, health; hardcoded `projectId`) | ✅ wired (needs `WHAPI_MASTER_TOKEN`) |
| **SchoolEXL OTP API** (email OTP) | `Http::post(apiadmin.schoolexl.com/...)` | `AuthController` (signup/reset OTP) | ✅ wired (hardcoded URL) |
| **Redis** | phpredis | `AuthController` (`Redis::setex` OTP cache) | ✅ used |
| **Twilio** | `twilio/sdk` | — | ❌ unused (dead dep) |
| AWS S3/SES/SQS/DynamoDB, Slack, Postmark, Resend | config stubs | — | ❌ configured, not used |
| **AI / LLM** | — | `space_iq`/`agentPrompt` store prompt text | ❌ **no actual LLM API is called** — "agent" = prompt storage + WhatsApp provisioning only |

### Frontend
| Integration | Detail |
|---|---|
| **Backend API** `api.easycoders.in` | the single external service, under `projects/orvell/public/api/...` |
| **sonner** | primary toasts (global) |
| **react-hot-toast** | one page only — redundant |
| **lucide-react** | icons |
| **next/font Geist** | ⚠️ imported but never applied to `<body>` (broken wiring) |
| Flag images | rendered from the country API's `flag` field (not a hardcoded CDN) |
| **Google/Apple OAuth** | UI stubs only — no SDK, missing icon assets |
| Analytics / Sentry / Vercel | none |

---

## 11. Dead Code, Unused Dependencies & Deprecated Packages

### Backend
- **Stray file** `ql -u orvell_user -p` (delete; info leak).
- **Dead files:** `AuthhController.php` (unrouted duplicate; logs OTP in plaintext, leaks `$e->getFile/Line/Message` to responses), `config/passport.php` (Passport not installed), `OtpCode` model (never used).
- **Missing class:** `Appointment` — referenced by `Customer`/`Client` relations but **does not exist** → `->appointments()` throws.
- **9 empty scaffold controllers** (Bale, BaleBatch, Buyer, Container, DailySnapshot, Invoice, InvoiceItem, Release, Reservation) + their 10 empty migrations/models — implement or remove.
- **Unused deps:** `twilio/sdk` (zero usages); `spatie/laravel-permission` (installed/migrated, no code use).
- **Deprecated usage:** Stripe legacy `Charge` API (prefer PaymentIntents).
- **Bugs (latent runtime errors):** `paymentHistoryy` typo, `tokenCheck`/`logoutall` missing methods, `$this->error()` missing on trait, `orders`/`users`/`products` column mismatches, `OrderResource.order_amount`.
- **Commented-out blocks:** `routes/api.php` (17, 62–66), `Order.php`, `StockManagement.php`, `WhatsappMessageService.php` (incl. commented `dd()`), `bootstrap/app.php` (whole first copy).
- **Default-only seeders/factories** (no countries/roles/domain data).

### Frontend
- **`forgotPasswordApi`** (`allApi.tsx:33`) — defined, **never imported** (the page uses `passwordResetApi`). Dead.
- **`react-hot-toast`** — used on one page; remove and migrate to global `sonner`.
- **Unused font imports** — `Geist`/`Geist_Mono` imported in `layout.tsx` but never applied; `globals.css` references undefined `--font-geist-*` vars.
- **~78-line commented "Agent IQ Modal"** block + dead `agentIQPrompt` state in `profile/page.tsx`.
- **18 `console.*` calls** left in — several log credentials/OTP payloads (`signup/page.tsx:136`, `forgot-password` 37/71/119) — minor info leak.
- **Portability smell:** the `projects/orvell/public/api/...` prefix is baked into ~40 call sites; one fully hardcoded absolute URL (`profile/page.tsx:69`); commented `withCredentials`.
- **Missing public assets** referenced by code: `/google-icon.png`, `/apple-icon.png`, `Vector.png`.
- **Duplicated `.dark` CSS block** in `globals.css`.

---

## Appendix — Prioritized Cleanup Recommendations

> Documentation only — no code was changed.

### 🔴 Critical (blocks core functionality)
1. **Add the missing migrations** for `clients`, `products`, `countries`, `spaces`, `space_iqs`, `agentDetails`, `agentPrompt`, `client_customer` (and a `countries` seeder) — without these, signup/login/stocks/country all fail on a clean DB.
2. **Reconcile column names** across `orders`/`users`/`products` migrations ↔ models ↔ requests ↔ resources.
3. **Fix broken route targets:** `paymentHistoryy`→`paymentHistory`, add `tokenCheck`/`logoutall`, add `$this->error()` (or use `fail()`), create/remove `Appointment`.

### 🟠 High
4. Document required secrets in `.env.example` + `config/services.php` (`STRIPE_SECRET`, `WHAPI_MASTER_TOKEN`); stop reading them via raw `env()`.
5. Tighten CORS (`allowed_origins`), ensure `APP_DEBUG=false` in prod, add rate-limiting to auth/OTP routes.
6. Remove credential/OTP `console.*` logging (FE) and exception-detail leakage (BE `AuthhController`).
7. Delete the stray `ql -u orvell_user -p` file.

### 🟡 Medium
8. Remove dead code/deps: `AuthhController.php`, `config/passport.php`, `OtpCode`, `twilio/sdk` (BE); `react-hot-toast`, `forgotPasswordApi`, unused Geist imports, commented modal block (FE).
9. Consolidate the **two frontend HTTP stacks** onto one (prefer the axios instance for its interceptors); extract a single API path-prefix constant.
10. Standardize the backend JSON response envelope; extract shared UUID/blame trait; move Form Requests to `App\Http\Requests`.

### 🟢 Low
11. Add a test framework + smoke tests (both repos) and CI (GitHub Actions).
12. De-duplicate the `.dark` CSS block; add the missing `public/` icon assets; either implement or remove the logistics scaffold (bales/invoices/etc.).

---

*End of audit.*
