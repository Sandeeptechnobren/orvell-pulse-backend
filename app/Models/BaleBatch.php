<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaleBatch extends Model
{
    protected $table = 'tbl_bale_batches';

    protected $fillable = [
        'container_id',
        'supplier_id',
        'category_id',
        'arrival_date',
        'qty_total',
        'qty_available',
        'qty_sold',
        'qty_released',
        'qty_damaged',
    ];

    protected $casts = [
        'arrival_date'  => 'date',
        'qty_total'     => 'integer',
        'qty_available' => 'integer',
        'qty_sold'      => 'integer',
        'qty_released'  => 'integer',
        'qty_damaged'   => 'integer',
    ];

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'category_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'bale_batch_id');
    }
}
