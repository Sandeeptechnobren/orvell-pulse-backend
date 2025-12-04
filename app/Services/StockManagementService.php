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

    public function getById($id)
    {
        return StockManagement::where('id', $id)
            ->whereNull('deleted_at')
            ->first();
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $item = StockManagement::findOrFail($id);
            $item->update($data);

            return $item;
        });
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $item = StockManagement::findOrFail($id);

            return $item->update([
                'deleted_at' => now()
            ]);
        });
    }
}
