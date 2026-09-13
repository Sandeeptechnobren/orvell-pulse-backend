<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'sender',
        'channel_id',
        'last_message_at',
        'status',
        'processing',
        'chat_id',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'processing' => 'boolean',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}