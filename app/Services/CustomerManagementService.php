<?php

namespace App\Services;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerManagementService
{
public function list()
    {
        return Customer::whereNull('deleted_at')
            ->orderBy('id', 'asc')
            ->get();
    }
public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return Customer::create($data);
        });
    }
public function getByUuid($uuid)
    {
        return Customer::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }
public function update($uuid, array $data)
    {
        return DB::transaction(function () use ($uuid, $data) {
            $item = Customer::where('uuid', $uuid)->firstOrFail();
            $item->update($data);
            return $item;
        });
    }
    public function delete($uuid)
    {
        return DB::transaction(function () use ($uuid) {
            $item = Customer::where('uuid', $uuid)->firstOrFail();
            return $item->delete(['deleted_at' => now()]);
        });
    }
}
