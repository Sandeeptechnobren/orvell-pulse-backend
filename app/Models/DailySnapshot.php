<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DailySnapshot extends Model
{
    protected $table = 'daily_snapshots';

    protected $fillable = [
        'uuid',
        'company_id',
        'client_id',
        'snapshot_date',
        'opening_stock_units',
        'opening_stock_value',
        'units_added',
        'units_sold',
        'units_released',
        'units_adjusted',
        'closing_stock_units',
        'closing_stock_value',
        'category_breakdown',
        'container_breakdown',
    ];

    protected $casts = [
        'snapshot_date'       => 'date',
        'opening_stock_units' => 'integer',
        'opening_stock_value' => 'decimal:2',
        'units_added'         => 'integer',
        'units_sold'          => 'integer',
        'units_released'      => 'integer',
        'units_adjusted'      => 'integer',
        'closing_stock_units' => 'integer',
        'closing_stock_value' => 'decimal:2',
        'category_breakdown'  => 'array',
        'container_breakdown' => 'array',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
