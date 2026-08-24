<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    protected $fillable = [
        'uuid',
        'client_id',
        'company_name',
        'company_code',
        'currency',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->company_code)) {
                $count = static::withTrashed()->count() + 1;
                $model->company_code = 'COMP-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class, 'company_id');
    }

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'company_id');
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class, 'company_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'company_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'company_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'company_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'company_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'company_id');
    }

    public function bankDeposits(): HasMany
    {
        return $this->hasMany(BankDeposit::class, 'company_id');
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(DailySnapshot::class, 'company_id');
    }

    public function dailyReconciliations(): HasMany
    {
        return $this->hasMany(DailyReconciliation::class, 'company_id');
    }
}
