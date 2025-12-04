<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomerManagement extends Model
{
    use SoftDeletes;

    protected $table = 'customer_management';

    protected $fillable = [
        'uuid',
        'name',
        'phone_no',
        'whatsapp_no',
        'address_1',
        'address_2',
        'district',
        'state',
        'zip_code',
        'created_by',
        'updated_by',
        'deleted_at'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (auth()->check()) {
                $model->created_by = auth()->id();
            }

            $model->created_at = now();
            $model->updated_at = now();
        });

        static::updating(function ($model) {

            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }

            $model->updated_at = now();
        });
    }
}
