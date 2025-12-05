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
        'customer_name',
        'total_amount',
        'status',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            // UUID generate
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            // Auto Order No generate: ORD001, ORD002
            if (empty($model->order_no)) {
                $last = self::orderBy('id', 'desc')->first();
                $num = $last ? intval(substr($last->order_no, 3)) + 1 : 1;
                $model->order_no = 'ORD' . str_pad($num, 3, '0', STR_PAD_LEFT);
            }

            // created_by auto set
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
