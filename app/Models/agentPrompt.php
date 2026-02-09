<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 *     @OA\Property(property="created_at", type="string", example="2025-01-01 10:00:00"),
 *     @OA\Property(property="updated_at", type="string", example="2025-01-01 10:30:00")
 * )
 */
class agentPrompt extends Model
{
    use SoftDeletes;
    protected $table = 'agentPrompt';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $fillable = [
        'uuid',
        'user_id',
        'prompt_description'
    ];
    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
