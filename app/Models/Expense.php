<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Expense extends Model
{
    use SoftDeletes;

    protected $table = 'expenses';

    protected $fillable = [
        'uuid',
        'expense_number',
        'company_id',
        'client_id',
        'staff_id',
        'category',
        'amount',
        'currency',
        'description',
        'receipt_image',
        'expense_date',
        'is_verified',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'expense_date' => 'date',
        'is_verified'  => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->expense_number)) {
                $last = static::withTrashed()->where('expense_number', 'like', 'EXP-%')
                    ->orderByRaw('CAST(SUBSTRING(expense_number, 5) AS UNSIGNED) DESC')
                    ->value('expense_number');
                $num = ($last && preg_match('/EXP-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->expense_number = 'EXP-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->expense_date)) {
                $model->expense_date = now()->toDateString();
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

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
