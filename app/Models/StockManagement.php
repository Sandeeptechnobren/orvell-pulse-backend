<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StockManagement extends Model
{
    use SoftDeletes;

    protected $table = 'item_category';

    protected $fillable = [
        'code',
        'category_name',
        'category_type',
        'created_by',
        'updated_by',
        'deleted_at'
    ];
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            // Auto code generator
            if (empty($model->code)) {
                $last = self::where('code', 'like', 'CAT%')
                    ->orderBy('id', 'desc')
                    ->first();

                if ($last && preg_match('/CAT(\d+)/', $last->code, $m)) {
                    $num = intval($m[1]) + 1;
                } else {
                    $num = 1;
                }

                $model->code = 'CAT' . str_pad($num, 3, '0', STR_PAD_LEFT);
            }

            // created_by auto fill
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }

            $model->created_at = now();
            $model->updated_at = now();
        });

        static::updating(function ($model) {

            // updated_by auto fill
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }

            $model->updated_at = now();
        });
    }
    
}