<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="CustomerManagement",
 *     type="object",
 *     @OA\Property(property="uuid", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", example="john@example.com"),
 *     @OA\Property(property="phone", type="string", example="9876543210"),
 *     @OA\Property(property="created_at", type="string", example="2025-01-01 10:00:00"),
 *     @OA\Property(property="updated_at", type="string", example="2025-01-01 10:30:00")
 * )
 */

class CustomerManagement extends Model
{
    use SoftDeletes;

    protected $table = 'customer_management';

    protected $fillable = [
        'customer_code',
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

            if (empty($model->customer_code)) {
                $latest = self::withTrashed()->orderBy('id', 'desc')->first();
                $nextNumber = $latest ? $latest->id + 1 : 1;
                $model->customer_code = 'CUST-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
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
