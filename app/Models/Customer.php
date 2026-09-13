<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\SoftDeletes;
// use Illuminate\Database\Eloquent\Relations\BelongsToMany;
// use Illuminate\Database\Eloquent\Relations\HasMany;
// use Illuminate\Support\Str;

// class Customer extends Model
// {
//     use SoftDeletes;

//     protected $table = 'customers';

//     protected $fillable = [
//         'uuid',
//         'buyer_id',
//         'name',
//         'whatsapp_number',
//         'wa_id',
//         'email',
//         'address',
//         'city',
//         'country',
//         'zipcode',
//         'preferred_categories',
//         'onboarding_status',
//         'meta',
//     ];

//     protected $casts = [
//         'onboarding_status' => 'integer',
//         'meta'              => 'array',
//     ];

//     protected static function boot()
//     {
//         parent::boot();

//         static::creating(function ($model) {
//             if (empty($model->uuid)) {
//                 $model->uuid = (string) Str::uuid();
//             }
//             if (empty($model->buyer_id)) {
//                 $last = static::withTrashed()->where('buyer_id', 'like', 'BUY%')
//                     ->orderByRaw('CAST(SUBSTRING(buyer_id, 4) AS UNSIGNED) DESC')
//                     ->value('buyer_id');
//                 $num = ($last && preg_match('/BUY(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
//                 $model->buyer_id = 'BUY' . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
//             }
//         });
//     }

//     public function clients(): BelongsToMany
//     {
//         return $this->belongsToMany(Client::class, 'client_customer')
//             ->withPivot('first_interaction_at', 'source')
//             ->withTimestamps();
//     }

//     public function orders(): HasMany
//     {
//         return $this->hasMany(Order::class, 'customer_id');
//     }
// }

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'uuid',
        'buyer_id',
        'name',
        'whatsapp_number',
        'wa_id',
        'email',
        'address',
        'city',
        'country',
        'zipcode',
        'preferred_categories',
        'onboarding_status',
        'meta',
    ];

    protected $casts = [
        'onboarding_status'     => 'integer',
        'meta'                  => 'array',
        'preferred_categories'  => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->buyer_id)) {
                $last = static::withTrashed()->where('buyer_id', 'like', 'BUY%')
                    ->orderByRaw('CAST(SUBSTRING(buyer_id, 4) AS UNSIGNED) DESC')
                    ->value('buyer_id');
                $num = ($last && preg_match('/BUY(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->buyer_id = 'BUY' . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_customer')
            ->withPivot('first_interaction_at', 'source')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
}
