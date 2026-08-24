<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use SoftDeletes;

    protected $table = 'payments';

    protected $fillable = [
        'uuid',
        'payment_number',
        'payment_id',
        'customer_email',
        'amount',
        'currency',
        'status',
        'response',
        'invoice_id',
        'order_id',
        'buyer_id',
        'company_id',
        'client_id',
        'payment_method',
        'payment_reference',
        'payment_proof_image',
        'cashier_id',
        'notes',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'response' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->payment_number)) {
                $last = static::withTrashed()->where('payment_number', 'like', 'PAY-%')
                    ->orderByRaw('CAST(SUBSTRING(payment_number, 5) AS UNSIGNED) DESC')
                    ->value('payment_number');
                $num = ($last && preg_match('/PAY-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->payment_number = 'PAY-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->payment_id)) {
                $model->payment_id = $model->payment_reference ?? 'PAY-' . strtoupper(Str::random(8));
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
