<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentDetails extends Model
{
    use SoftDeletes;
    protected $table = 'agentDetails';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $fillable = [
        'uuid',
        'user_id',
        '_isPremium',
        'instance_id',
        'creationTS',
        'ownerId',
        'activeTill',
        'token',
        'server',
        'stopped',
        'status',
        'name',
        'projectId',
    ];
    protected $casts = [
        '_isPremium' => 'boolean',
        'stopped'    => 'boolean',
        'creationTS' => 'integer',
        'activeTill' => 'integer',
        'server'     => 'integer',
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
