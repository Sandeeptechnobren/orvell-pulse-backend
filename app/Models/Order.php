<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'uuid',
        'invoice_code',
        'client_id',
        'space_id',
        'customer_id',
        'product_id',
        'order_quantity',
        'order_amount',
        'payment_origin',
        'payment_method',
        'payment_status',
        'payment_reference',
        'status',
        'pickup_code',
        'pickup_status',
        'company_name',
        'salesperson_id',
        'cashier_id',
        'container_id',
        'address',
        'notes',
    ];

    public function space()
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(StockManagement::class, 'product_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            // Auto-generate invoice code: INV-0001, INV-0002, ...
            if (empty($model->invoice_code)) {
                $last = static::withTrashed()->where('invoice_code', 'like', 'INV-%')
                    ->orderByRaw('CAST(SUBSTRING(invoice_code, 5) AS UNSIGNED) DESC')
                    ->value('invoice_code');
                $num = ($last && preg_match('/INV-(\d+)/', $last, $im)) ? ((int) $im[1] + 1) : 1;
                $model->invoice_code = 'INV-'.str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            //  Order No: ORD001
            // if (empty($model->order_no)) {
            //     $last = self::withTrashed()->orderBy('id', 'desc')->first();
            //     $num  = $last ? intval(substr($last->order_no, 3)) + 1 : 1;
            //     $model->order_no = 'ORD' . str_pad($num, 3, '0', STR_PAD_LEFT);
            // }
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}
