<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['reservation_id', 'medicine_id', 'medicine_name', 'unit_price', 'quantity'];

    protected function casts(): array
    {
        return [
            'medicine_id' => 'integer',
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
