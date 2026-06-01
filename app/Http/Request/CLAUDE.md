# app/Http/Request/ — FormRequest validators

## Purpose
Holds validation rules for write endpoints. Each class extends
`Illuminate\Foundation\Http\FormRequest`, returns `authorize(): true`, and defines `rules()`.
Note the NON-standard singular namespace `App\Http\Request` (Laravel convention is plural).

## Key files
- `StockManagementRequest.php` — rules for stock create/update.
- `Item_categoryRequest.php` — item-category rules; uses
  `Rule::unique('item_category','code')->ignore($uuid,'uuid')`.
- `CustomerManagementRequest.php` — customer create/update rules.

## Data flow
Type-hinted as a controller method argument (e.g. `store(Item_categoryRequest $request)`);
Laravel auto-validates before the method body runs; `$request->validated()` returns the
clean array passed to the service. Validation failure → automatic 422 JSON.

## Dependencies
Used by the matching controllers in `app/Http/Controllers/`. Rules should mirror the model
`$fillable` and DB columns.

## Conventions
`authorize()` returns `true` (authorization is handled by route middleware, not here).
⚠ Singular `App\Http\Request` namespace — match it for new files (or migrate the whole
folder to plural). ⚠ Keep request keys, model `$fillable`, and migration columns in sync —
Stock currently validates fields the model can't mass-assign (audit §4).

## Common commands
- `php artisan make:request XRequest` — ⚠ generates under `App\Http\Requests`; move/rename
  to match this folder's singular namespace.
