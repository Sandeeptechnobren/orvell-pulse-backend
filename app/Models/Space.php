<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Space extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'chatbot_name',
        'space_phone',
        'is_active',
        'category',
        'country',
        'currency',
        'image',
        'start_time',
        'end_time',
        'last_update',
        'uuid'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
     protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}