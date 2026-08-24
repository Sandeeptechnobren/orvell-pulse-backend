<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class InventoryTransaction extends Model
{
    protected $table = 'inventory_transactions';

    protected $fillable = [
        'uuid',
        'company_id',
        'client_id',
        'container_id',
        'bale_id',
        'product_id',
        'item_category_id',
        'transaction_type',
        'quantity',
        'unit_price',
        'reference_type',
        'reference_id',
        'staff_id',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function bale(): BelongsTo
    {
        return $this->belongsTo(Bale::class, 'bale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockManagement::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'item_category_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
