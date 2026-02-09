<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Item_category extends Model
{
    use SoftDeletes;

    protected $table = 'item_category';

    protected $fillable = [
        'uuid',
        'code',
        'category_name',
        'category_type',
        'created_by',
        'updated_by'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            // UUID generate
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            // Auto code generate (safe)
            if (empty($model->code)) {
                $lastCode = self::withTrashed()
                    ->where('code', 'like', 'CAT%')
                    ->max(DB::raw('CAST(SUBSTRING(code, 4) AS UNSIGNED)'));

                $num = $lastCode ? $lastCode + 1 : 1;
                $model->code = 'CAT' . str_pad($num, 3, '0', STR_PAD_LEFT);
            }

            // created_by
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}
