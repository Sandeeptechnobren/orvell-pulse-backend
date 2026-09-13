<?php

namespace App\Services;

use App\Models\BaleBatch;
use App\Models\Container;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BaleService
{
    public function __construct(
        private readonly StockNotificationService $stockNotifications
    ) {
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = BaleBatch::query()->with(['container', 'supplier', 'category']);

        if (!empty($filters['container_id'])) {
            $query->where('container_id', $filters['container_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (($filters['status'] ?? '') === 'available') {
            $query->where('qty_available', '>', 0);
        } elseif (($filters['status'] ?? '') === 'sold_out') {
            $query->where('qty_available', 0);
        }

        return $query
            ->orderByDesc('arrival_date')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Register incoming stock: each line adds quantity to the batch for that
     * container + category (created on first arrival).
     */
    public function bulkCreate(int $containerId, array $lines): Collection
    {
        $batches = DB::transaction(function () use ($containerId, $lines) {
            $container = Container::lockForUpdate()->findOrFail($containerId);

            if ($container->status === 'closed') {
                throw new RuntimeException(
                    'This container is closed. Stock cannot be added to a closed container.'
                );
            }

            $touched = collect();

            foreach ($lines as $line) {
                $qty = (int) $line['quantity'];

                $batch = BaleBatch::lockForUpdate()->firstOrCreate(
                    [
                        'container_id' => $container->id,
                        'category_id'  => (int) $line['category_id'],
                    ],
                    [
                        'supplier_id'  => $container->supplier_id,
                        'arrival_date' => $container->arrival_date,
                    ]
                );

                $batch->increment('qty_total', $qty);
                $batch->increment('qty_available', $qty);

                StockMovement::create([
                    'bale_batch_id'  => $batch->id,
                    'container_id'   => $container->id,
                    'movement_type'  => 'arrival',
                    'reference_type' => 'container',
                    'reference_id'   => $container->id,
                    'quantity'       => $qty,
                    'movement_date'  => now(),
                    'created_by'     => auth()->id(),
                ]);

                $touched->push($batch->id);
            }

            return BaleBatch::with(['container', 'supplier', 'category'])
                ->whereIn('id', $touched->unique())
                ->get();
        });

        // Announce the arrival to customers (queued per recipient, after commit).
        $this->stockNotifications->broadcastArrival($lines);

        return $batches;
    }

    public function find(int $batchId): BaleBatch
    {
        return BaleBatch::with(['container', 'supplier', 'category', 'stockMovements'])
            ->findOrFail($batchId);
    }

    /**
     * Quantity adjustments on a batch:
     *  - mark_damaged: move N from available to damaged
     *  - restore_damaged: move N from damaged back to available
     *  - correct: add or remove N available (count correction; negative allowed)
     */
    public function adjust(int $batchId, array $data): BaleBatch
    {
        return DB::transaction(function () use ($batchId, $data) {
            $batch = BaleBatch::lockForUpdate()->findOrFail($batchId);

            if (!empty($data['mark_damaged'])) {
                $qty = (int) $data['mark_damaged'];
                if ($qty > $batch->qty_available) {
                    throw new RuntimeException(
                        "Only {$batch->qty_available} available; cannot mark {$qty} as damaged."
                    );
                }
                $batch->decrement('qty_available', $qty);
                $batch->increment('qty_damaged', $qty);
                $this->logAdjustment($batch, 'damaged', $qty, $data['notes'] ?? null);
            }

            if (!empty($data['restore_damaged'])) {
                $qty = (int) $data['restore_damaged'];
                if ($qty > $batch->qty_damaged) {
                    throw new RuntimeException(
                        "Only {$batch->qty_damaged} damaged; cannot restore {$qty}."
                    );
                }
                $batch->decrement('qty_damaged', $qty);
                $batch->increment('qty_available', $qty);
                $this->logAdjustment($batch, 'damage_restored', $qty, $data['notes'] ?? null);
            }

            if (isset($data['correct']) && (int) $data['correct'] !== 0) {
                $qty = (int) $data['correct'];
                if ($qty < 0 && abs($qty) > $batch->qty_available) {
                    throw new RuntimeException(
                        "Only {$batch->qty_available} available; cannot remove " . abs($qty) . '.'
                    );
                }
                $batch->increment('qty_available', $qty);
                $batch->increment('qty_total', $qty);
                $this->logAdjustment($batch, 'correction', $qty, $data['notes'] ?? null);
            }

            return $batch->fresh(['container', 'supplier', 'category']);
        });
    }

    public function delete(int $batchId): void
    {
        DB::transaction(function () use ($batchId) {
            $batch = BaleBatch::lockForUpdate()->findOrFail($batchId);

            if ($batch->qty_sold > 0 || $batch->qty_released > 0) {
                throw new RuntimeException(
                    'This batch has sales or releases recorded and cannot be deleted. Use a correction instead.'
                );
            }

            $batch->stockMovements()->delete();
            $batch->delete();
        });
    }

    public function stockSummary(): Collection
    {
        return BaleBatch::query()
            ->join('tbl_containers', 'tbl_containers.id', '=', 'tbl_bale_batches.container_id')
            ->join('item_category', 'item_category.id', '=', 'tbl_bale_batches.category_id')
            ->selectRaw('
                tbl_containers.container_id,
                tbl_bale_batches.arrival_date,
                item_category.category_name,
                tbl_bale_batches.qty_total as total_bales,
                tbl_bale_batches.qty_available as available,
                tbl_bale_batches.qty_sold as sold,
                tbl_bale_batches.qty_released as released,
                tbl_bale_batches.qty_damaged as damaged
            ')
            ->orderByDesc('tbl_bale_batches.arrival_date')
            ->get();
    }

    private function logAdjustment(BaleBatch $batch, string $type, int $qty, ?string $notes): void
    {
        StockMovement::create([
            'bale_batch_id'  => $batch->id,
            'container_id'   => $batch->container_id,
            'movement_type'  => $type,
            'reference_type' => 'adjustment',
            'quantity'       => $qty,
            'movement_date'  => now(),
            'created_by'     => auth()->id(),
            'notes'          => $notes,
        ]);
    }
}
