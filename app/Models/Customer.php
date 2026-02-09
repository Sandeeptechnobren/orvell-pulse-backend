<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use app\Models\Clients;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\Appointment;


class Customer extends Model
{
    protected $fillable = ['uuid',
        'name',
        'whatsapp_number',
        'email',
        'address',
        'country',
        'onboarding_status',
        'meta',
        'zipcode',];

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
    public function clients()
        {
            return $this->belongsToMany(Client::class, 'client_customer')
                        ->withPivot('first_interaction_at', 'source')
                        ->withTimestamps();
        }
    public function orders()
    {
        return $this->hasMany(Order::class);
    }


}
