<?php

namespace App\Services;

use App\Models\CustomerManagement;
use Illuminate\Support\Facades\DB;

class CustomerManagementService
{
    public function list()
    {
        return CustomerManagement::whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return CustomerManagement::create($data);
        });
    }

    public function getByUuid($uuid)
    {
        return CustomerManagement::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    public function update($uuid, array $data)
    {
        return DB::transaction(function () use ($uuid, $data) {
            $item = CustomerManagement::where('uuid', $uuid)->firstOrFail();
            $item->update($data);
            return $item;
        });
    }

    public function delete($uuid)
    {
        return DB::transaction(function () use ($uuid) {
            $item = CustomerManagement::where('uuid', $uuid)->firstOrFail();
            return $item->update(['deleted_at' => now()]);
        });
    }
}
