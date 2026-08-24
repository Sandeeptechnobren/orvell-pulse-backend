<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="StockManagement",
 *     type="object",
 *     @OA\Property(property="uuid", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="name", type="string", example="Summer Dresses Grade A"),
 *     @OA\Property(property="stock", type="integer", example=50),
 *     @OA\Property(property="price", type="number", example=250.00),
 *     @OA\Property(property="created_at", type="string", example="2026-01-01 10:00:00")
 * )
 */
class StockManagement extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'uuid',
        'client_id',
        'space_id',
        'company_id',
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'unit',
        'type',
        'stock',
        'sku',
        'category',
        'image',
        'tags',
        'is_featured',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'stock'       => 'integer',
        'tags'        => 'array',
        'is_featured' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function getCodeAttribute()
    {
        return $this->sku;
    }

    public function getProductNameAttribute()
    {
        return $this->name;
    }

    public function getAvailableUnitAttribute()
    {
        return $this->stock;
    }

    public function getOriginalPriceAttribute()
    {
        return $this->price;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->sku)) {
                $last = self::withTrashed()
                    ->where('sku', 'like', 'STK%')
                    ->orderByRaw('CAST(SUBSTRING(sku, 4) AS UNSIGNED) DESC')
                    ->value('sku');
                $num = ($last && preg_match('/STK(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->sku = 'STK' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (auth()->check() && empty($model->created_by)) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'category', 'id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'product_id');
    }
}