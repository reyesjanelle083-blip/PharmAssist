<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    // Only created_at exists on this table.
    const UPDATED_AT = null;

    protected $table = 'stock_movements';

    protected $fillable = ['branch_inventory_id', 'delta', 'reason', 'actor_id', 'request_id'];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'actor_id' => 'integer',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }
}
