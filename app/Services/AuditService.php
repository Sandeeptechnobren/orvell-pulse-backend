<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    /**
     * Log an immutable audit record.
     *
     * @param string $action
     * @param Model|string $auditable
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $companyId
     * @param string|null $actorName
     * @param int|null $userId
     * @return AuditLog
     */
    public function log(
        string $action,
        $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $companyId = null,
        ?string $actorName = null,
        ?int $userId = null
    ): AuditLog {
        $user = auth()->user();

        $auditableType = is_object($auditable) ? get_class($auditable) : (string) $auditable;
        $auditableId = is_object($auditable) && isset($auditable->id) ? (int) $auditable->id : 0;

        $resolvedCompanyId = $companyId
            ?? ($user && isset($user->company_id) ? $user->company_id : null)
            ?? (is_object($auditable) && isset($auditable->company_id) ? $auditable->company_id : null);

        $resolvedActorName = $actorName
            ?? ($user ? ($user->name ?? $user->email ?? 'Authenticated User') : 'System');

        $resolvedUserId = $userId ?? ($user ? $user->id : null);

        return AuditLog::create([
            'user_id'        => $resolvedUserId,
            'actor_name'     => $resolvedActorName,
            'action'         => $action,
            'auditable_type' => $auditableType,
            'auditable_id'   => $auditableId,
            'company_id'     => $resolvedCompanyId,
            'old_values'     => $oldValues,
            'new_values'     => $newValues,
            'ip_address'     => request()?->ip(),
            'user_agent'     => request()?->userAgent(),
            'created_at'     => now(),
        ]);
    }

    /**
     * Helper for model creation logging.
     */
    public function logCreate(Model $model, string $action, ?int $companyId = null): AuditLog
    {
        return $this->log(
            action: $action,
            auditable: $model,
            oldValues: null,
            newValues: $model->toArray(),
            companyId: $companyId
        );
    }

    /**
     * Helper for model update logging.
     */
    public function logUpdate(Model $model, string $action, array $oldValues, array $newValues, ?int $companyId = null): AuditLog
    {
        return $this->log(
            action: $action,
            auditable: $model,
            oldValues: $oldValues,
            newValues: $newValues,
            companyId: $companyId
        );
    }

    /**
     * Helper for model deletion logging.
     */
    public function logDelete(Model $model, string $action, ?int $companyId = null): AuditLog
    {
        return $this->log(
            action: $action,
            auditable: $model,
            oldValues: $model->toArray(),
            newValues: null,
            companyId: $companyId
        );
    }
}
