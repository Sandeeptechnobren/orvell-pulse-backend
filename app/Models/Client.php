<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Str;

class Client extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes, HasRoles;

    protected $table = 'clients';

    protected $fillable = [
        'uuid',
        'name',
        'business_name',
        'business_location',
        'phone_number',
        'email',
        'password',
        'security_question',
        'security_answer',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->business_name)) {
                $model->business_name = $model->name ?? 'Orvell Business';
            }
            if (empty($model->business_location)) {
                $model->business_location = 'Accra';
            }
            if (empty($model->phone_number)) {
                $model->phone_number = '+233000000000';
            }
        });
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'client_id');
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class, 'client_id');
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'client_customer')
            ->withPivot('first_interaction_at', 'source')
            ->withTimestamps();
    }
}
