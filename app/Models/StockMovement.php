<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $table = 'tbl_stock_movements';

    protected $fillable = [
        'bale_id',
        'container_id',
        'movement_type',
        'reference_type',
        'reference_id',
        'quantity',
        'movement_date',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'quantity' => 'integer',
    ];

    public function bale(): BelongsTo
    {
        return $this->belongsTo(Bale::class, 'bale_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }
}