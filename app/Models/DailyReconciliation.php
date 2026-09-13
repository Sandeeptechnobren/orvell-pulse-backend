<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DailyReconciliation extends Model
{
    protected $table = 'daily_reconciliations';

    protected $fillable = [
        'uuid',
        'company_id',
        'client_id',
        'reconciliation_date',
        'total_sales_amount',
        'total_cash_collected',
        'total_bank_deposits',
        'total_expenses',
        'pending_invoices_count',
        'pending_invoices_amount',
        'stock_variance_units',
        'status',
        'discrepancies',
        'reconciled_by',
    ];

    protected $casts = [
        'reconciliation_date'    => 'date',
        'total_sales_amount'     => 'decimal:2',
        'total_cash_collected'   => 'decimal:2',
        'total_bank_deposits'    => 'decimal:2',
        'total_expenses'         => 'decimal:2',
        'pending_invoices_count' => 'integer',
        'pending_invoices_amount'=> 'decimal:2',
        'stock_variance_units'   => 'integer',
        'discrepancies'          => 'array',
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

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
