<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvoiceAmendmentRequest extends Model
{
    protected $table = 'invoice_amendment_requests';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'requested_by',
        'reason',
        'requested_changes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'amended_invoice_id',
    ];

    protected $casts = [
        'requested_changes' => 'array',
        'reviewed_at'       => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function amendedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'amended_invoice_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
