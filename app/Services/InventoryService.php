<?php

namespace App\Services;

use App\Models\BaleBatch;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Apply a sale allocation plan: each entry moves quantity from available
     * to sold on its batch. Plan entries: ['batch' => BaleBatch (locked),
     * 'quantity' => int]. Caller runs inside a transaction and holds the locks.
     */
    public function consumeFromBatches(array $plan, int $orderId, ?int $userId = null): void
    {
        foreach ($plan as $entry) {
            $batch = $entry['batch'];
            $qty = (int) $entry['quantity'];

            if ($qty > $batch->qty_available) {
                throw ValidationException::withMessages([
                    'items' => ["Stock changed while processing: only {$batch->qty_available} left in batch #{$batch->id}."],
                ]);
            }

            $batch->decrement('qty_available', $qty);
            $batch->increment('qty_sold', $qty);

            StockMovement::create([
                'bale_batch_id'  => $batch->id,
                'container_id'   => $batch->container_id,
                'movement_type'  => 'sale',
                'reference_type' => 'order',
                'reference_id'   => $orderId,
                'quantity'       => $qty,
                'movement_date'  => now(),
                'created_by'     => $userId,
            ]);
        }
    }

    /**
     * Release sold quantities (goods physically left the warehouse) for the
     * given invoice lines: [['bale_batch_id' => int, 'quantity' => int], ...].
     * Run inside a transaction.
     */
    public function releaseFromBatches(array $lines, int $orderId, ?int $userId = null): void
    {
        foreach ($lines as $line) {
            $batch = BaleBatch::lockForUpdate()->find($line['bale_batch_id']);
            if (!$batch) {
                continue;
            }

            $qty = (int) $line['quantity'];

            if ($qty > $batch->qty_sold) {
                throw ValidationException::withMessages([
                    'pickup' => ["Batch #{$batch->id} has only {$batch->qty_sold} sold units; cannot release {$qty}."],
                ]);
            }

            $batch->decrement('qty_sold', $qty);
            $batch->increment('qty_released', $qty);

            StockMovement::create([
                'bale_batch_id'  => $batch->id,
                'container_id'   => $batch->container_id,
                'movement_type'  => 'release',
                'reference_type' => 'order',
                'reference_id'   => $orderId,
                'quantity'       => $qty,
                'movement_date'  => now(),
                'created_by'     => $userId,
            ]);
        }
    }

    /**
     * Return sold quantities to available stock (cancelled sale before pickup).
     */
    public function returnToStock(array $lines, int $orderId, ?int $userId = null): void
    {
        foreach ($lines as $line) {
            $batch = BaleBatch::lockForUpdate()->find($line['bale_batch_id']);
            if (!$batch) {
                continue;
            }

            $qty = (int) $line['quantity'];

            if ($qty > $batch->qty_sold) {
                throw ValidationException::withMessages([
                    'items' => ["Batch #{$batch->id} has only {$batch->qty_sold} sold units; cannot return {$qty}."],
                ]);
            }

            $batch->decrement('qty_sold', $qty);
            $batch->increment('qty_available', $qty);

            StockMovement::create([
                'bale_batch_id'  => $batch->id,
                'container_id'   => $batch->container_id,
                'movement_type'  => 'sale_cancelled',
                'reference_type' => 'order',
                'reference_id'   => $orderId,
                'quantity'       => $qty,
                'movement_date'  => now(),
                'created_by'     => $userId,
            ]);
        }
    }
}
