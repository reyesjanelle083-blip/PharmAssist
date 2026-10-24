<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlert extends Model
{
    // Only created_at exists on this table.
    const UPDATED_AT = null;

    protected $table = 'stock_alerts';

    protected $fillable = ['branch_inventory_id', 'alert_type', 'is_resolved', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }
}
