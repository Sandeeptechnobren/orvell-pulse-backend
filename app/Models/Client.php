<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
class Client extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'business_name',
        'business_location',
        'phone_number',
        'email',
        'password',
        'security_question',
        'security_answer',
        'uuid',
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
        });
    }
    public function appointments()
        {
            return $this->hasMany(Appointment::class);
        }

    public function customers()
        {
            return $this->belongsToMany(Customer::class, 'client_customer')
                        ->withPivot('first_interaction_at', 'source')
                        ->withTimestamps();
        }
    }

            

    




