<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="StockManagement",
 *     type="object",
 *     @OA\Property(property="uuid", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="product_name", type="string", example="iPhone 15"),
 *     @OA\Property(property="quantity", type="integer", example=50),
 *     @OA\Property(property="price", type="number", example=79999),
 *     @OA\Property(property="created_at", type="string", example="2025-01-01 10:00:00")
 * )
 */

class StockManagement extends Model
{
    use SoftDeletes;

    protected $table = 'products';
    protected $fillable = [
        'client_id',
        'space_id',
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
        'uuid',
        ];
        protected $casts = [
        'available_unit'  => 'integer',
        'original_price'  => 'decimal:2',
        'discount_price'  => 'decimal:2',
    ];

    /**
     * Accessor
     * Frontend ke liye "code" naam se sku expose hoga
    */
    public function getCodeAttribute()
    {
        return $this->sku;
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
                ->orderBy('id', 'desc')
                ->first();
                
                if ($last && preg_match('/STK(\d+)/', $last->sku, $m)) {
                    $num = (int) $m[1] + 1;
                    } else {
                        $num = 1;
                        }
                        
                        $model->sku = 'STK' . str_pad($num, 4, '0', STR_PAD_LEFT);
                        }
                        
                        if (auth()->check()) {
                            $model->created_by = auth()->id();
                            }
                            });
                            
                            static::updating(function ($model) {
                                if (auth()->check()) {
                                    $model->updated_by = auth()->id();
                                    }
                                    });
                                    }
public function itemCategory()
{
    return $this->belongsTo(Item_category::class, 'category', 'id');
}

                                        }
                                        

// protected $fillable = [
//     'uuid',
//     'sku',              
//     'item_name',
//     'item_category_id',
//     'available_unit',
//     'original_price',
//     'discount_price',
//     'offer',
//     'client_id',
//     'space_id',
//     'created_by',
//     'updated_by',
// ];