<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'medicine' => (new MedicineResource($this->medicine))->resolve(),
            'price' => $this->price,
            'stock_quantity' => $this->stock_quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'available_quantity' => $this->available_quantity, // computed, never stored
            'min_threshold' => $this->min_threshold,
            'batch_no' => $this->batch_no,
            'expiration_date' => $this->expiration_date?->toDateString(),
            'status' => $this->status,
            'version' => $this->version,
        ];
    }
}
