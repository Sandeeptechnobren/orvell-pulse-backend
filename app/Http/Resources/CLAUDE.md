# app/Http/Resources/ — JSON output transformers

## Purpose
API Resource classes (`Illuminate\Http\Resources\Json\JsonResource`) that map Eloquent
models to the exact JSON field shape returned to clients, decoupling DB column names from
API field names.

## Key files
- `StockManagementResource.php` — maps product columns → API fields (e.g. `sku`→`code`,
  `name`→`item_name`, `stock`→`available_unit`).
- `Item_categoryResource.php` — exposes `id, uuid, code, category_name, category_type`.
- `CustomerManagementResource.php` — ⚠ mis-maps some fields (reads `Customer`-model names).
- `OrderResource.php` — ⚠ references `order_amount`, a column that doesn't exist (always 0.0).

## Data flow
A controller wraps a model/collection: `new XResource($model)` or
`XResource::collection($list)`; the resource's `toArray($request)` produces the JSON `data`
payload.

## Dependencies
Consume `app/Models/`. Used by controllers in `app/Http/Controllers/`.

## Conventions
One resource per model; `toArray()` returns an explicit field map; use `whenLoaded()` for
relations. ⚠ Field names referenced here must actually exist on the model/migration —
verify before adding (see audit §4 for current mismatches).

## Common commands
- `php artisan make:resource XResource`
