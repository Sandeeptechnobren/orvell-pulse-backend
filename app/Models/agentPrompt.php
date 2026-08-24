<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="AgentPrompt",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=10),
 *     @OA\Property(
 *         property="prompt_description",
 *         type="string",
 *         example="You are a helpful WhatsApp assistant"
 *     ),
 *     @OA\Property(property="created_at", type="string", example="2026-01-01 10:00:00"),
 *     @OA\Property(property="updated_at", type="string", example="2026-01-01 10:30:00")
 * )
 */
class agentPrompt extends Model
{
    protected $table = 'agentprompt';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'user_id',
        'client_id',
        'prompt_for',
        'prompt_description'
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
