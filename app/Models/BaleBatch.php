<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BaleBatch extends Model
{
    use SoftDeletes;

    protected $table = 'bale_batches';

    protected $fillable = [
        'uuid',
        'batch_code',
        'container_id',
        'item_category_id',
        'company_id',
        'total_quantity',
        'remaining_quantity',
        'unit_price',
    ];

    protected $casts = [
        'total_quantity'     => 'integer',
        'remaining_quantity' => 'integer',
        'unit_price'         => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->batch_code)) {
                $count = static::withTrashed()->count() + 1;
                $model->batch_code = 'BATCH-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'item_category_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'bale_batch_id');
    }
}
