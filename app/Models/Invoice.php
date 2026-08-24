<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'uuid',
        'invoice_number',
        'original_invoice_id',
        'amendment_version',
        'order_id',
        'buyer_id',
        'company_id',
        'client_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'payment_status',
        'status',
        'issued_date',
        'due_date',
        'created_by',
        'approved_by',
        'notes',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'issued_date'     => 'date',
        'due_date'        => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->invoice_number)) {
                $last = static::withTrashed()->where('invoice_number', 'like', 'INV-%')
                    ->whereNull('original_invoice_id')
                    ->orderByRaw('CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED) DESC')
                    ->value('invoice_number');
                $num = ($last && preg_match('/INV-(\d+)/', $last, $m)) ? ((int) $m[1] + 1) : 1;
                $base = 'INV-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
                $model->invoice_number = $model->amendment_version ? "{$base}-{$model->amendment_version}" : $base;
            }
            if (empty($model->issued_date)) {
                $model->issued_date = now()->toDateString();
            }
            if (auth()->check() && empty($model->created_by) && auth()->user() instanceof User) {
                $model->created_by = auth()->id();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Invoice::class, 'original_invoice_id');
    }

    public function amendmentRequests(): HasMany
    {
        return $this->hasMany(InvoiceAmendmentRequest::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
