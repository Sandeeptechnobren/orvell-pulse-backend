# Orvell Pulse — Patterns & Style Guide

> **Purpose:** the canonical reference for how code is written in this codebase. Every example below is a **real, unmodified snippet** with its actual file path. Where the dominant pattern is inconsistent, the inconsistency is flagged and a **➡️ Convention** line states the version to follow in new code.
>
> **Date:** 2026-06-01 · **Repos:** `orvell-pulse-backend/` (Laravel 12), `orvell-pulse/` (Next.js 16). No application code was modified to produce this guide. Companions: `CODEBASE_AUDIT.md`, `ARCHITECTURE.md`.

---

## 1. API Route / Endpoint Structure

Routes are declared in `routes/api.php`, grouped by a `Route::prefix(...)` block, and map to a controller action. Controllers inject their service via the constructor and stay thin.

**`orvell-pulse-backend/routes/api.php`**
```php
Route::prefix('item-category')->group(function () {
    Route::get('/list',          [Item_categoryController::class, 'index']);
    Route::post('/add',          [Item_categoryController::class, 'store']);
    Route::get('/show/{uuid}',   [Item_categoryController::class, 'show']);
    Route::put('/update/{uuid}', [Item_categoryController::class, 'update']);
    Route::delete('/delete/{uuid}', [Item_categoryController::class, 'destroy']);
});
```

**`orvell-pulse-backend/app/Http/Controllers/Item_categoryController.php`**
```php
class Item_categoryController extends Controller
{
    protected $service;
    public function __construct(Item_categoryService $service) { $this->service = $service; }

    public function store(Item_categoryRequest $request)
    {
        $item = $this->service->create($request->validated());
        return response()->json(['message' => 'Item category created', 'data' => new Item_categoryResource($item)]);
    }
}
```

➡️ **Convention:** one resource per `Route::prefix` group; action verbs `list / add / show/{uuid} / update/{uuid} / delete/{uuid}`; controller method names follow Laravel REST (`index/store/show/update/destroy`); validation via a typed FormRequest argument; business logic delegated to a constructor-injected `*Service`. *(Note: path casing is inconsistent across modules — see §11.)*

---

## 2. Database Queries (ORM Pattern)

Eloquent is used throughout; writes are wrapped in `DB::transaction`. Raw SQL appears only inside a model boot hook.

**`orvell-pulse-backend/app/Services/Item_categoryService.php`**
```php
public function list()
{
    return Item_category::whereNull('deleted_at')->orderBy('id', 'asc')->get();
}

public function create(array $data)
{
    return DB::transaction(fn () => Item_category::create($data));
}

public function getByUuid($uuid)
{
    return Item_category::where('uuid', $uuid)->whereNull('deleted_at')->firstOrFail();
}
```

**Raw-SQL example** (auto-incrementing code in a model boot) — `app/Models/Item_category.php`:
```php
$lastCode = self::withTrashed()
    ->where('code', 'like', 'CAT%')
    ->max(DB::raw('CAST(SUBSTRING(code, 4) AS UNSIGNED)'));
```

➡️ **Convention:** query via Eloquent models; wrap create/update/delete in `DB::transaction`; fetch single records by `uuid` with `firstOrFail()`; reserve `DB::raw` for expressions that have no fluent equivalent. *(Note: the explicit `whereNull('deleted_at')` is redundant on `SoftDeletes` models, which already scope soft-deletes automatically.)*

---

## 3. Error Handling

**Backend** — two coexisting mechanisms: (a) let `firstOrFail()` throw and rely on Laravel's default exception → JSON handler (404/422), and (b) explicit failure responses via `ResponseTrait::fail()`.

**`orvell-pulse-backend/app/Traits/ResponseTrait.php`**
```php
public function fail($message = 'Failed', $code = 400, $data = [])
{
    return response()->json(['status' => false, 'message' => $message, 'data' => $data], $code);
}
```

**Frontend** — a single `handleError()` normalizes every axios/network error into the shared `ApiResponse`, and service functions wrap calls in `try/catch`.

**`orvell-pulse/app/serviceApi/APlutis.tsx`**
```ts
export const handleError = (error: unknown): ApiResponse => {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError<{ message?: string; error?: string }>;
    if (axiosError.response) {
      return { success: false, message: axiosError.response.data?.message ?? "An error occurred",
               status: axiosError.response.status, data: axiosError.response.data };
    }
    if (axiosError.request) return { success: false, message: "Network error. Please check your connection.", status: 0 };
  }
  return { success: false, message: error instanceof Error ? error.message : "An unexpected error occurred", status: 0 };
};
```

➡️ **Convention:** backend — throw `firstOrFail()` for "not found", use `ResponseTrait::fail($msg, $code)` for explicit errors (do **not** invent ad-hoc shapes; see §10). Frontend — never call the API raw in a component; go through a service function that returns `ApiResponse` via `try/catch` + `handleError`, and surface it with `toast`.

---

## 4. Authentication / Middleware on Routes

Public routes sit at the top of `routes/api.php`; everything requiring a logged-in principal goes inside a single `auth:sanctum` group.

**`orvell-pulse-backend/routes/api.php`**
```php
// public
Route::post('signin', [AuthController::class, 'login']);
Route::get('Country', [CountryController::class, 'countries']);

// protected — Sanctum bearer token required
Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::prefix('Stocks')->group(function () {
        Route::get('/list', [StockManagementController::class, 'index']);
        // ...
    });
});
```

Tokens are minted on login: `$client->createToken('api_token', [], now()->addDays(7))->plainTextToken` (`API/AuthController@login`).

➡️ **Convention:** add new authenticated endpoints **inside** the existing `Route::middleware('auth:sanctum')->group(...)`; keep only signup/signin/OTP/reset/country public. There is no role/permission middleware in use (`spatie/laravel-permission` is installed but dormant).

---

## 5. Environment Variables & Config Access

**Backend** reads secrets directly with `env()` (note: this is the current pattern, but it is an anti-pattern — values are `null` after `php artisan config:cache`).

**`orvell-pulse-backend/app/Http/Controllers/PaymentController.php`**
```php
Stripe::setApiKey(env('STRIPE_SECRET'));
```

**Frontend** reads `NEXT_PUBLIC_*` vars with a hardcoded fallback.

**`orvell-pulse/lib/api.ts`**
```ts
export const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "https://api.easycoders.in";
```

➡️ **Convention:** Frontend — only `NEXT_PUBLIC_API_BASE_URL` is read (keep a single source; avoid hardcoded URLs elsewhere). Backend — for **new** code prefer adding a key to `config/services.php` and reading via `config('services.stripe.secret')` rather than `env()` in controllers, so config caching works in production.

---

## 6. New Module / Feature Folder Organization

A feature is **not** a single folder — it is a fixed set of files across Laravel's conventional directories, plus a route group. The "Item Category" feature is the cleanest template:

| Layer | File |
|---|---|
| Route group | `routes/api.php` → `Route::prefix('item-category')->group(...)` |
| Controller | `app/Http/Controllers/Item_categoryController.php` |
| Validation | `app/Http/Request/Item_categoryRequest.php` |
| Output shape | `app/Http/Resources/Item_categoryResource.php` |
| Business logic | `app/Services/Item_categoryService.php` |
| Model | `app/Models/Item_category.php` |
| Schema | `database/migrations/*_create_item_category_table.php` |

➡️ **Convention:** when adding a feature `Foo`, create `FooController`, `FooRequest`, `FooResource`, `FooService`, `Foo` model + migration, and a `Route::prefix('foo')` group. Controllers call the service; services own transactions and queries; resources shape the JSON. *(Standard Laravel convention is the plural `app/Http/Requests/`; this repo uses the singular `App\Http\Request` namespace — match the existing namespace for consistency, or migrate the whole folder.)*

---

## 7. Tests

PHPUnit with two suites. Only skeleton tests exist today; use them as the structural template.

**Integration / Feature (boots the app, hits HTTP)** — `orvell-pulse-backend/tests/Feature/ExampleTest.php`
```php
namespace Tests\Feature;
use Tests\TestCase;            // extends the app TestCase (boots Laravel)

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }
}
```

**Unit (no framework boot)** — `orvell-pulse-backend/tests/Unit/ExampleTest.php`
```php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;   // plain PHPUnit, no Laravel

class ExampleTest extends TestCase
{
    public function test_that_true_is_true(): void { $this->assertTrue(true); }
}
```

➡️ **Convention:** Feature tests extend `Tests\TestCase` and exercise routes via `$this->get/post(...)` with `assert*`; Unit tests extend `PHPUnit\Framework\TestCase`. Add `use RefreshDatabase;` for DB-touching feature tests (the test env uses `sqlite :memory:` per `phpunit.xml`). Method names are `snake_case` and prefixed `test_`. Run with `composer test` / `php artisan test`. **Frontend has no test framework — none should be assumed; one must be added before writing FE tests.**

---

## 8. Background Jobs / Queue Tasks

➡️ **Not applicable — no example exists.** There are no `Job` classes (`app/Jobs` is absent) and nothing is ever dispatched (no `ShouldQueue`, `dispatch()`, `Bus::`, `Mail::`, `Notification::`, or scheduled tasks anywhere in `app/`). The `database` queue driver and `queue:listen` (in the `composer dev` script) are configured but unused.

**Current substitute pattern — work runs synchronously inside the request**, e.g. the Stripe charge in `app/Http/Controllers/PaymentController.php`:
```php
$charge  = Charge::create([...]);          // blocking external call, in-request
$payment = Payment::create([...]);
return response()->json(['message' => 'Payment successful', 'data' => $payment]);
```

➡️ **Convention (if async is introduced):** generate with `php artisan make:job`, implement `ShouldQueue`, move the external/slow call (Stripe, WHAPI, OTP send) into `handle()`, and `dispatch()` it from the service — then a queue worker (`php artisan queue:work`) is required in deployment. Until then, treat all integration calls as synchronous.

---

## 9. Frontend Component Structure

Two real conventions: reusable UI primitives and route page components.

**Reusable primitive (shadcn/ui + CVA)** — `orvell-pulse/components/ui/button.tsx`
```tsx
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"

const buttonVariants = cva("inline-flex items-center ...", {
  variants: { variant: { default: "...", outline: "...", ghost: "..." }, size: { default: "...", sm: "...", lg: "..." } },
  defaultVariants: { variant: "default", size: "default" },
})

function Button({ className, variant, size, asChild = false, ...props }:
  React.ComponentProps<"button"> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "button"
  return <Comp data-slot="button" className={cn(buttonVariants({ variant, size, className }))} {...props} />
}
export { Button, buttonVariants }
```

**Route page (client component)** — `orvell-pulse/app/auth/signin/page.tsx`
```tsx
"use client";
import { useFormik } from "formik";
import { toast } from "sonner";
import { signinApi } from "../../serviceApi/allApi";
import { useAuth } from "@/context/auth-provider";

export default function SignInPage() {
  const { setToken } = useAuth();
  const formik = useFormik({
    initialValues: { email: "", password: "" },
    validate: (values) => { /* ...inline errors... */ },
    onSubmit: async (values) => {
      const promise = signinApi(values).then((res) => { if (!res.success) throw new Error(res.message); return res.data; });
      toast.promise(promise, { loading: "Signing in...", success: (d) => { setToken(d.token); return d.message; }, error: (e) => e.message });
    },
  });
  return ( /* JSX form using formik.handleSubmit / handleChange */ );
}
```

➡️ **Convention:** UI primitives in `components/ui/` are `cva` + `cn()` + `data-slot`, **named** exports, variant-driven. Pages are `"use client"` (when interactive), a single **default**-exported `PascalCase` function, state via hooks/context, forms via `useFormik` with inline `validate`, async results surfaced through `toast.promise` and a service function (never a raw fetch in the component). Styling is Tailwind utility classes with semantic theme tokens (`bg-primary`, `text-muted-foreground`).

---

## 10. API Response Shapes

**Backend success/failure (the standard, via `ResponseTrait`)** — `app/Traits/ResponseTrait.php`
```php
// success: HTTP 2xx
{ "status": true,  "message": "...", "data": { } }
// fail: HTTP 4xx
{ "status": false, "message": "...", "data": [] }
```

**Most CRUD controllers, however, use an ad-hoc shape** — `Item_categoryController@index`:
```php
return response()->json(['message' => 'Item categories fetched', 'data' => Item_categoryResource::collection($this->service->list())]);
// → { "message": "...", "data": [ ... ] }   (no "status" key)
```
*(And `PaymentController@paymentHistoryy` returns a bare array `response()->json($payments)`. The audit found 3–4 different envelopes in use.)*

**Frontend normalized shape** — every service call resolves to this — `app/serviceApi/APlutis.tsx`:
```ts
export interface ApiResponse<T = any> {
  success: boolean;
  message: string;
  status: number;
  data?: T;
}
```

➡️ **Convention:** **standardize backend responses on `ResponseTrait`** → `{ status, message, data }` for both success and error (this is the documented target; new endpoints should not introduce new shapes). The frontend already coerces everything to `ApiResponse<T>` and treats `response.data?.status !== false` as success — keep that contract.

---

## 11. Naming Conventions

Observed across the codebase (with the dominant/target convention called out):

| Thing | Observed | ➡️ Convention for new code |
|---|---|---|
| **PHP classes** | Mostly `PascalCase` (`StockManagement`, `OrderController`); some lowercase/snake (`space_iq`, `agentPrompt`, `Item_category`) | `PascalCase` (`SpaceIq`, `AgentPrompt`, `ItemCategory`) |
| **Backend files** | `XController.php`, `XService.php`, `XRequest.php`, `XResource.php`, `Model.php` (one class per file, PSR-4) | keep the `X<Layer>.php` suffix pattern |
| **Controller methods** | REST verbs `index/store/show/update/destroy`; helpers `camelCase` (`getByUuid`, `updateByUuid`) | same (watch typos — `paymentHistoryy` is a real bug) |
| **PHP variables** | `camelCase` (`$lastCode`, `$item`) | `camelCase` |
| **DB columns** | `snake_case` (`category_name`, `created_by`, `customer_email`) | `snake_case` |
| **DB tables** | Mixed: plural snake (`payments`, `orders`); singular/irregular (`item_category`, `customer_management`, `agentDetails`, `agentPrompt`); model `StockManagement` maps to table `products` | `snake_case` plural (`item_categories`, `agent_details`) |
| **API path segments** | Inconsistent casing: `/Stocks`, `/Country` (Pascal); `/item-category` (kebab); `/customer`, `/orders`, `/payment` (lower); actions `/list`, `/add`, `/show/{uuid}` | lowercase **kebab-case**, plural nouns (`/stocks`, `/item-categories`) |
| **Frontend files** | `kebab-case` (`auth-provider.tsx`, `main-content.tsx`); App Router pages always `page.tsx`/`layout.tsx` | `kebab-case`; reserved Next filenames as-is |
| **React components** | `PascalCase` functions (`SignInPage`, `Button`) | `PascalCase` |
| **TS vars/functions** | `camelCase` (`getApiUrl`, `signinApi`, `setToken`) | `camelCase` |

➡️ **Convention:** the casing of models, tables, and API paths is currently inconsistent — **new code should converge on**: `PascalCase` classes, `snake_case` plural tables, `camelCase` PHP/TS members, and lowercase kebab-case plural API paths.

---

## 12. Import / Export Patterns

**Backend (PHP):** PSR-4, one namespaced class per file, dependencies pulled in with `use` at the top.

**`orvell-pulse-backend/app/Http/Controllers/Item_categoryController.php`**
```php
namespace App\Http\Controllers;

use App\Http\Request\Item_categoryRequest;
use App\Services\Item_categoryService;
use App\Http\Resources\Item_categoryResource;
```

**Frontend (TS):**
- **Named exports** for utilities, components, types, and service functions:
  ```ts
  // lib/utils.ts
  export function cn(...inputs: ClassValue[]) { ... }
  export type StoredUser = { ... } | null
  // components/ui/button.tsx
  export { Button, buttonVariants }
  // app/serviceApi/allApi.tsx
  export const signinApi = async (data: any): Promise<ApiResponse> => { ... }
  ```
- **Default export** for route components only:
  ```ts
  // app/auth/signin/page.tsx
  export default function SignInPage() { ... }
  ```
- **Path alias `@/*` → project root** (`tsconfig.json` → `"paths": { "@/*": ["./*"] }`), used as `@/lib/utils`, `@/context/auth-provider`. Relative imports also appear (`../../serviceApi/allApi`).

➡️ **Convention:** Backend — `use` imports, one class per file. Frontend — **default export** for `page.tsx`/`layout.tsx` route files; **named exports** for everything else (components, hooks, utils, services, types). Prefer the **`@/` alias** over deep relative paths (`../../`) for cross-folder imports.

---

*End of patterns & style guide.*
