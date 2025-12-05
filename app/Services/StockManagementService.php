<?php

namespace App\Services;

use App\Models\StockManagement;
use Illuminate\Support\Facades\DB;

class StockManagementService
{
    public function list()
    {
        return StockManagement::whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return StockManagement::create($data);
        });
    }

    public function getByUuid($uuid)
    {
        return StockManagement::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    public function updateByUuid($uuid, array $data)
    {
        return DB::transaction(function () use ($uuid, $data) {

            $item = StockManagement::where('uuid', $uuid)->firstOrFail();
            $item->update($data);

            return $item;
        });
    }

    public function deleteByUuid($uuid)
    {
        return DB::transaction(function () use ($uuid) {

            $item = StockManagement::where('uuid', $uuid)->firstOrFail();

            return $item->update([
                'deleted_at' => now()
            ]);
        });
    }
}
