<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Buyer extends Model
{
    use SoftDeletes;

    protected $table = 'buyers';

    protected $fillable = [
        'uuid',
        'buyer_id',
        'name',
        'whatsapp_number',
        'wa_id',
        'email',
        'category',
        'region',
        'priority_level',
        'preferred_categories',
        'address',
        'city',
        'country',
        'zipcode',
        'onboarding_status',
        'company_id',
        'client_id',
        'is_active',
    ];

    protected $casts = [
        'preferred_categories' => 'array',
        'onboarding_status'    => 'integer',
        'is_active'            => 'boolean',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'buyer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'buyer_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'buyer_id');
    }

    public function scopeRegular($query)
    {
        return $query->where('category', 'REGULAR');
    }

    public function scopeOccasional($query)
    {
        return $query->where('category', 'OCCASIONAL');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('buyer_id', 'like', "%{$term}%")
              ->orWhere('whatsapp_number', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('city', 'like', "%{$term}%");
        });
    }
}
