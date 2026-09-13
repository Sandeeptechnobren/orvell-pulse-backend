<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bale extends Model
{
    protected $table = 'tbl_bales';

    protected $fillable = [
        'bale_id',
        'container_id',
        'supplier_id',
        'category_id',
        'arrival_date',
        'status',
    ];

    protected $casts = [
        'arrival_date' => 'date',
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
        return $this->hasMany(StockMovement::class, 'bale_id');
    }
}