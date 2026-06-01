# app/Traits/ — shared traits

## Purpose
Cross-cutting reusable behavior mixed into controllers. Currently a single shared
response-formatting trait that defines the API's intended JSON envelope.

## Key files
- `ResponseTrait.php` — `success($message, $data = null, $status = 200)` →
  `{status:true, message, data}`; `fail($message = 'Failed', $code = 400, $data = [])` →
  `{status:false, message, data}`.

## Data flow
A controller does `use ResponseTrait;` then returns `$this->success(...)` / `$this->fail(...)`,
producing a consistent JSON body + HTTP status code.

## Dependencies
Used by `app/Http/Controllers/` (e.g. `StockManagementController`, `API/AuthController`).
Depends on nothing else.

## Conventions
This is the **target** response envelope — new endpoints should use it instead of ad-hoc
`response()->json(['message'=>…,'data'=>…])` (PATTERNS §10). ⚠ There is no `error()` method;
`StockManagementController` calls `$this->error()` (a bug) — use `fail()` instead.

## Common commands
- No generator — add new traits by hand under namespace `App\Traits`.
