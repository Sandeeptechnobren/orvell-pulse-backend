<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="Payment",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="payment_id", type="string", example="ch_3NabcXYZ"),
 *     @OA\Property(property="customer_email", type="string", example="user@example.com"),
 *     @OA\Property(property="amount", type="number", example=500),
 *     @OA\Property(property="currency", type="string", example="inr"),
 *     @OA\Property(property="status", type="string", example="succeeded"),
 *     @OA\Property(property="created_at", type="string", example="2025-01-01 10:00:00")
 * )
 */

class Payment extends Model
{
    protected $fillable = [
        'customer_email',
        'payment_id',
        'amount',
        'currency',
        'status',
        'response'
    ];
}
