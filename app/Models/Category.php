<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'tbl_categories';

    protected $fillable = [
        'category_code',
        'name',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function bales(): HasMany
    {
        return $this->hasMany(Bale::class, 'category_id');
    }
}