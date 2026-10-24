<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // Only created_at exists on this table.
    const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = ['actor_id', 'action', 'entity', 'entity_id', 'ip', 'request_id'];
}
