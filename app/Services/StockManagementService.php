<?php

namespace App\Services;

use App\Models\StockManagement;
use App\Models\Space;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\StockManagementResource;
 

class StockManagementService
{
public function list()
{
    $user = Auth::user();

    $items = StockManagement::where('client_id', $user->id)
        ->with('itemCategory:id,category_name')
        ->get();
    

    // return $items;
    return StockManagementResource::collection($items);
}
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {

            $clientId = Auth::id();

            if (!$clientId) {
                throw new \Exception('Unauthenticated');
            }

            // 🔑 client_id & created_by from token
            $data['client_id']  = $clientId;
            $data['created_by'] = $clientId;

            /**
             * 🔑 space_id auto resolve
             * Agar space nahi mila to auto-create
             */
            $space = Space::where('client_id', $clientId)
                ->orderBy('id', 'asc')
                ->first();

            if (!$space) {
                $space = Space::create([
                    'client_id' => $clientId,
                    'name'      => 'Default Space',
                ]);
            }

            $data['space_id'] = $space->id;

            return StockManagement::create($data);
        });
    }

    public function getByUuid(string $uuid)
    {
        return StockManagement::where('uuid', $uuid)
            ->where('client_id', Auth::id())
            ->first();
    }

    public function update(string $uuid, array $data)
    {
        return DB::transaction(function () use ($uuid, $data) {

            $stock = $this->getByUuid($uuid);

            if (!$stock) {
                return null;
            }

            $data['updated_by'] = Auth::id();

            $stock->update($data);

            return $stock;
        });
    }

    public function delete(string $uuid): bool
    {
        return DB::transaction(function () use ($uuid) {

            $stock = $this->getByUuid($uuid);

            if (!$stock) {
                return false;
            }

            $stock->delete();

            return true;
        });
    }
}
