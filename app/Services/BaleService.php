<?php

namespace App\Services;

use App\Models\Bale;
use App\Models\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BaleService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Bale::query()->with(['container', 'supplier', 'category']);

        if (!empty($filters['container_id'])) {
            $query->where('container_id', $filters['container_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where('bale_id', 'like', '%' . $filters['search'] . '%');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderBy('bale_id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function bulkCreate(int $containerId, array $lines): Collection
    {
        return DB::transaction(function () use ($containerId, $lines) {
            $container = Container::lockForUpdate()->findOrFail($containerId);

            if ($container->status === 'closed') {
                throw new RuntimeException(
                    'This container is closed. Bales cannot be added to a closed container.'
                );
            }

            $sequence = $this->nextSequence($container->id);
            $created = collect();

            foreach ($lines as $line) {
                for ($i = 0; $i < $line['quantity']; $i++) {
                    $created->push(Bale::create([
                        'bale_id' => $this->makeBaleId($container->container_id, $sequence++),
                        'container_id' => $container->id,
                        'supplier_id' => $container->supplier_id,
                        'category_id' => $line['category_id'],
                        'arrival_date' => $container->arrival_date,
                        'status' => 'in_stock',
                    ]));
                }
            }

            return $created->load('category');
        });
    }

    public function find(Bale $bale): Bale
    {
        return $bale->load(['container', 'supplier', 'category', 'stockMovements']);
    }

    public function update(Bale $bale, array $data): Bale
    {
        if (isset($data['status'])) {
            $this->assertValidTransition($bale->status, $data['status']);
        }

        $bale->update($data);

        return $bale->fresh(['container', 'supplier', 'category']);
    }

    public function delete(Bale $bale): void
    {
        if ($bale->status !== 'in_stock') {
            throw new RuntimeException(
                "Bale {$bale->bale_id} is {$bale->status} and cannot be deleted. Only in-stock bales can be removed."
            );
        }

        if ($bale->stockMovements()->exists()) {
            throw new RuntimeException(
                "Bale {$bale->bale_id} has stock movements and cannot be deleted. Mark it as damaged instead."
            );
        }

        $bale->delete();
    }

    public function stockSummary(): Collection
    {
        return Bale::query()
            ->join('tbl_containers', 'tbl_containers.id', '=', 'tbl_bales.container_id')
            ->join('tbl_categories', 'tbl_categories.id', '=', 'tbl_bales.category_id')
            ->selectRaw("
                tbl_containers.container_id,
                tbl_containers.arrival_date,
                tbl_categories.category_name,
                COUNT(*) as total_bales,
                SUM(CASE WHEN tbl_bales.status = 'in_stock' THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN tbl_bales.status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN tbl_bales.status = 'released' THEN 1 ELSE 0 END) as released,
                SUM(CASE WHEN tbl_bales.status = 'damaged' THEN 1 ELSE 0 END) as damaged
            ")
            ->groupBy('tbl_containers.container_id', 'tbl_containers.arrival_date', 'tbl_categories.category_name')
            ->orderByDesc('tbl_containers.arrival_date')
            ->get();
    }

    private function nextSequence(int $containerId): int
    {
        $last = Bale::where('container_id', $containerId)
            ->orderByDesc('id')
            ->value('bale_id');

        if (!$last) {
            return 1;
        }

        return (int) substr($last, strrpos($last, '-B') + 2) + 1;
    }

    private function makeBaleId(string $containerCode, int $sequence): string
    {
        return $containerCode . '-B' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    private function assertValidTransition(string $from, string $to): void
    {
        $allowed = [
            'in_stock' => ['sold', 'damaged'],
            'sold' => ['released', 'in_stock'],
            'released' => [],
            'damaged' => ['in_stock'],
        ];

        if ($from !== $to && !in_array($to, $allowed[$from] ?? [], true)) {
            throw new RuntimeException(
                "Cannot change a bale from '{$from}' to '{$to}'."
            );
        }
    }
}