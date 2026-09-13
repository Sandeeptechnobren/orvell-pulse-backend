<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BankDeposit extends Model
{
    use SoftDeletes;

    protected $table = 'bank_deposits';

    protected $fillable = [
        'uuid',
        'deposit_number',
        'company_id',
        'client_id',
        'cashier_id',
        'bank_name',
        'account_number',
        'amount',
        'currency',
        'reference_number',
        'deposit_slip_image',
        'deposit_date',
        'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'deposit_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->deposit_number)) {
                $last = static::withTrashed()->where('deposit_number', 'like', 'DEP-%')
                    ->orderByRaw('CAST(SUBSTRING(deposit_number, 5) AS UNSIGNED) DESC')
                    ->value('deposit_number');
                $num = ($last && preg_match('/DEP-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->deposit_number = 'DEP-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->deposit_date)) {
                $model->deposit_date = now()->toDateString();
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

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
