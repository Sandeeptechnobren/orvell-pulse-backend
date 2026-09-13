<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'uuid',
        'order_no',
        'invoice_code',
        'customer_name',
        'buyer_id',
        'customer_id',
        'company_id',
        'client_id',
        'space_id',
        'product_id',
        'container_id',
        'salesperson_id',
        'cashier_id',
        'order_quantity',
        'total_amount',
        'order_amount',
        'currency',
        'payment_origin',
        'payment_status',
        'payment_method',
        'payment_reference',
        'status',
        'pickup_code',
        'pickup_status',
        'company_name',
        'address',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_quantity' => 'integer',
        'total_amount'   => 'decimal:2',
        'order_amount'   => 'decimal:2',
    ];

    public function getOrderAmountAttribute($value)
    {
        return $value !== null ? (float) $value : (float) ($this->attributes['total_amount'] ?? 0.0);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->order_no)) {
                $last = static::withTrashed()->where('order_no', 'like', 'ORD%')
                    ->orderByRaw('CAST(SUBSTRING(order_no, 5) AS UNSIGNED) DESC')
                    ->value('order_no');
                $num = ($last && preg_match('/ORD-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->order_no = 'ORD-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->invoice_code)) {
                $last = static::withTrashed()->where('invoice_code', 'like', 'INV-%')
                    ->orderByRaw('CAST(SUBSTRING(invoice_code, 5) AS UNSIGNED) DESC')
                    ->value('invoice_code');
                $num = ($last && preg_match('/INV-(\d+)/', $last, $im)) ? ((int) $im[1] + 1) : 1;
                $model->invoice_code = 'INV-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->pickup_code)) {
                $model->pickup_code = 'PKP-' . strtoupper(Str::random(6));
            }
            if (empty($model->customer_name)) {
                if (!empty($model->buyer_id) && $model->buyer) {
                    $model->customer_name = $model->buyer->name;
                } elseif (!empty($model->customer_id) && $model->customer) {
                    $model->customer_name = $model->customer->name;
                } else {
                    $model->customer_name = 'Valued Buyer';
                }
            }
            if (empty($model->total_amount) && !empty($model->order_amount)) {
                $model->total_amount = $model->order_amount;
            }
            if (empty($model->order_amount) && !empty($model->total_amount)) {
                $model->order_amount = $model->total_amount;
            }
            if (auth()->check() && auth()->user() instanceof User && empty($model->created_by)) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check() && auth()->user() instanceof User) {
                $model->updated_by = auth()->id();
            }
        });
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockManagement::class, 'product_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(Release::class, 'order_id');
    }

    public function reservedBales(): HasMany
    {
        return $this->hasMany(Bale::class, 'reserved_order_id');
    }
}
