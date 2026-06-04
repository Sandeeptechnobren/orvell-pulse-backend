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
        'wa_id',
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
            // Auto-generate buyer code: BUY001, BUY002, ...
            if (empty($model->buyer_id)) {
                $last = static::where('buyer_id', 'like', 'BUY%')
                    ->orderByRaw('CAST(SUBSTRING(buyer_id, 4) AS UNSIGNED) DESC')
                    ->value('buyer_id');
                $num = ($last && preg_match('/BUY(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->buyer_id = 'BUY'.str_pad((string) $num, 3, '0', STR_PAD_LEFT);
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
