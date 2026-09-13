<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'message_id',
        'sender',
        'from_me',
        'type',
        'message_timestamp',
        'sender_type',
        'source',
        'chat_id',
        'recipient',
        'text',
        'from_name',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'from_me' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}