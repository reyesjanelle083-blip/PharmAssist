<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockHold extends Model
{
    public $timestamps = false;

    protected $table = 'stock_holds';

    protected $fillable = ['hold_ref', 'reservation_ref', 'branch_inventory_id', 'quantity', 'status', 'expires_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }
}
