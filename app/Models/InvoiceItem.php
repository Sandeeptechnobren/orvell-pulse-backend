<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'bale_id',
        'bale_batch_id',
        'container_id',
        'product_id',
        'item_category_id',
        'item_description',
        'quantity',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->total_price) && $model->unit_price && $model->quantity) {
                $model->total_price = $model->unit_price * $model->quantity;
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function bale(): BelongsTo
    {
        return $this->belongsTo(Bale::class, 'bale_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BaleBatch::class, 'bale_batch_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockManagement::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'item_category_id');
    }
}
