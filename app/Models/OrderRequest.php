<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderRequest extends Model
{
    protected $table = 'tbl_order_requests';

    protected $fillable = [
        'uuid',
        'request_no',
        'customer_id',
        'status',
        'notes',
        'declined_reason',
        'actioned_by',
        'actioned_at',
        'order_id',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->request_no)) {
                $last = static::where('request_no', 'like', 'REQ-%')
                    ->orderByRaw('CAST(SUBSTRING(request_no, 5) AS UNSIGNED) DESC')
                    ->value('request_no');
                $num = ($last && preg_match('/REQ-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->request_no = 'REQ-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderRequestItem::class, 'order_request_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
