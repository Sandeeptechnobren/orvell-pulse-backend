<?php

namespace App\Services;

use App\Models\Item_category;
use Illuminate\Support\Facades\DB;

class Item_categoryService
{ 
    public function list()
    {
        return Item_category::whereNull('deleted_at')
            ->orderBy('id', 'asc')->get();
    }
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return Item_category::create($data);
        });
    }
    public function getByUuid($uuid)
    {
        return Item_category::where('uuid', $uuid)
            ->whereNull('deleted_at')->firstOrFail();
    }
    public function updateByUuid($uuid, array $data)
    {
        return DB::transaction(function () use ($uuid, $data) {
            $item = Item_category::where('uuid', $uuid)->firstOrFail();
            $item->update($data);
            return $item;
        });
    }
    public function deleteByUuid($uuid)
    {
        return DB::transaction(function () use ($uuid) {
            $item = Item_category::where('uuid', $uuid)->firstOrFail();
            return $item->delete([
                'deleted_at' => now()
            ]);
        });
    }
}
