<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'tbl_suppliers';

    protected $fillable = [
        'uuid',
        'supplier_code',
        'name',
        'phone_no',
        'email',
        'address_1',
        'address_2',
        'district',
        'state',
        'zip_code',
        'country',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class, 'supplier_id');
    }

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'supplier_id');
    }
}