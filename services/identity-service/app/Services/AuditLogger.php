<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Writes audit_logs rows. Callers must never pass passwords, tokens or verification codes.
 */
class AuditLogger
{
    public function record(string $action, ?int $actorId = null, string $entity = 'user', ?int $entityId = null): void
    {
        $request = request();

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'ip' => $request->ip(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
