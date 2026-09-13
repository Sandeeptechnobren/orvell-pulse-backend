<?php

namespace App\Services;

use App\Models\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ContainerService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Container::query()
            ->with('supplier')
            ->withCount('bales');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('container_id', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['arrival_from'])) {
            $query->whereDate('arrival_date', '>=', $filters['arrival_from']);
        }

        if (!empty($filters['arrival_to'])) {
            $query->whereDate('arrival_date', '<=', $filters['arrival_to']);
        }

        return $query
            ->orderByDesc('arrival_date')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data, int $userId): Container
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['container_id'] = $data['container_id']
                ?? $this->generateContainerId();
            $data['created_by'] = $userId;

            return Container::create($data)->load('supplier');
        });
    }

    public function find(Container $container): Container
    {
        return $container->load(['supplier', 'bales'])->loadCount('bales');
    }

    public function update(Container $container, array $data): Container
    {
        $container->update($data);

        return $container->fresh(['supplier'])->loadCount('bales');
    }

    public function delete(Container $container): void
    {
        if ($container->bales()->exists()) {
            throw new RuntimeException(
                'This container has bales linked to it and cannot be deleted. Close it instead.'
            );
        }

        $container->delete();
    }

    private function generateContainerId(): string
    {
        $year = now()->format('Y');
        $prefix = "CNT{$year}";

        $lastNumber = Container::where('container_id', 'like', $prefix . '%')
            ->lockForUpdate()
            ->selectRaw("MAX(CAST(SUBSTRING(container_id, " . (strlen($prefix) + 1) . ") AS UNSIGNED)) as max_no")
            ->value('max_no');

        return $prefix . str_pad(($lastNumber ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    }
}