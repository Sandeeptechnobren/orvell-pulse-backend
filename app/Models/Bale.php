<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Bale extends Model
{
    use SoftDeletes;

    protected $table = 'bales';

    protected $fillable = [
        'uuid',
        'bale_code',
        'container_id',
        'bale_batch_id',
        'item_category_id',
        'company_id',
        'client_id',
        'supplier_name',
        'arrival_date',
        'cost_price',
        'selling_price',
        'weight_kg',
        'status',
        'reserved_order_id',
        'released_at',
        'released_by',
        'qr_code',
    ];

    protected $casts = [
        'arrival_date'  => 'date',
        'cost_price'    => 'decimal:2',
        'selling_price' => 'decimal:2',
        'weight_kg'     => 'decimal:2',
        'released_at'   => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->bale_code)) {
                $year = date('Y');
                $count = static::withTrashed()->count() + 1;
                $model->bale_code = 'BALE-' . $year . '-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BaleBatch::class, 'bale_batch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Item_category::class, 'item_category_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function reservedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reserved_order_id');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'bale_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'bale_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeReserved($query)
    {
        return $query->where('status', 'reserved');
    }

    public function scopeReleased($query)
    {
        return $query->where('status', 'released');
    }

    public function scopeSold($query)
    {
        return $query->where('status', 'sold');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
