<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Container extends Model
{
    protected $table = 'tbl_containers';

    protected $fillable = [
        'container_id',
        'supplier_id',
        'arrival_date',
        'received_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'arrival_date' => 'date',
        'received_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'container_id');
    }
}