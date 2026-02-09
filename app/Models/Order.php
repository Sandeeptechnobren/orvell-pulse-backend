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
        'order_no',
        'space_id',
        'customer_id',
        'product_id',
        'order_quantity',
        'total_amount',
        'currency',
        'payment_status',
        'payment_method',
        'payment_reference',
        'status',
        'created_by',
        'updated_by',
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
