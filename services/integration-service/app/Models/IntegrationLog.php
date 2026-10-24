<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    // Only created_at exists on this table.
    const UPDATED_AT = null;

    protected $table = 'integration_logs';

    protected $fillable = ['request_id', 'provider', 'operation', 'status_code', 'outcome', 'latency_ms', 'error_message'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'latency_ms' => 'integer',
        ];
    }
}
