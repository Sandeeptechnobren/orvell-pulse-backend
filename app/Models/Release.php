<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Release extends Model
{
    use SoftDeletes;

    protected $table = 'releases';

    protected $fillable = [
        'uuid',
        'release_code',
        'order_id',
        'invoice_id',
        'buyer_id',
        'company_id',
        'client_id',
        'pickup_code',
        'released_by',
        'released_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->release_code)) {
                $last = static::withTrashed()->where('release_code', 'like', 'REL-%')
                    ->orderByRaw('CAST(SUBSTRING(release_code, 5) AS UNSIGNED) DESC')
                    ->value('release_code');
                $num = ($last && preg_match('/REL-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $model->release_code = 'REL-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
            }
            if (empty($model->released_at)) {
                $model->released_at = now();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
