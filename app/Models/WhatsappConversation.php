<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WhatsappConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'uuid',
        'wa_id',
        'role',
        'current_flow',
        'current_step',
        'state_payload',
        'last_interaction_at',
    ];

    protected $casts = [
        'current_step'        => 'integer',
        'state_payload'       => 'array',
        'last_interaction_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->last_interaction_at)) {
                $model->last_interaction_at = now();
            }
        });
    }
}
