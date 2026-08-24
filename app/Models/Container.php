<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Container extends Model
{
    use SoftDeletes;

    protected $table = 'containers';

    protected $fillable = [
        'uuid',
        'container_number',
        'supplier_name',
        'company_id',
        'client_id',
        'space_id',
        'arrival_date',
        'status',
        'total_bales',
        'remaining_bales',
        'shipping_ref',
        'notes',
    ];

    protected $casts = [
        'arrival_date'    => 'date',
        'total_bales'     => 'integer',
        'remaining_bales' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->container_number)) {
                $year = date('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $model->container_number = 'CONT-' . $year . '-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'container_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(BaleBatch::class, 'container_id');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'container_id');
    }

    public function scopeInStock($query)
    {
        return $query->where('status', 'in_stock');
    }
}
