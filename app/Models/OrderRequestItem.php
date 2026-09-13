<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRequestItem extends Model
{
    protected $table = 'tbl_order_request_items';

    protected $fillable = [
        'order_request_id',
        'category_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function orderRequest(): BelongsTo
    {
        return $this->belongsTo(OrderRequest::class, 'order_request_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'category_id');
    }
}
