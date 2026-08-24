<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AgentDetails extends Model
{
    protected $table = 'agentdetails';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
 
    protected $fillable = [
        'uuid',
        'user_id',
        'client_id',
        'agent_type',
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
