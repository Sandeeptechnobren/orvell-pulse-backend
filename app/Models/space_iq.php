<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class space_iq extends Model
{
    protected $fillable = [
    'space_id',
    'prompt_content',
    'attachments',
    'uuid',

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
}
