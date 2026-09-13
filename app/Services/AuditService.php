<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditService
{
    /**
     * Write a single audit log entry. Never throws — an audit failure must not
     * roll back the business transaction it documents.
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $companyId = null,
        ?int $userId = null
    ): ?AuditLog {
        try {
            $user = auth()->user();

            return AuditLog::create([
                'user_id'        => $userId ?? auth()->id(),
                'actor_name'     => $user?->name,
                'action'         => $action,
                'auditable_type' => $auditable ? get_class($auditable) : null,
                'auditable_id'   => $auditable?->getKey(),
                'company_id'     => $companyId,
                'old_values'     => $oldValues ?: null,
                'new_values'     => $newValues ?: null,
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log write failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function logCreate(Model $model, string $action, ?int $companyId = null): ?AuditLog
    {
        return $this->log(
            action: $action,
            auditable: $model,
            newValues: $model->toArray(),
            companyId: $companyId
        );
    }

    public function logUpdate(
        Model $model,
        string $action,
        array $oldValues,
        array $newValues,
        ?int $companyId = null
    ): ?AuditLog {
        return $this->log(
            action: $action,
            auditable: $model,
            oldValues: $oldValues,
            newValues: $newValues,
            companyId: $companyId
        );
    }
}
