<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'reservation_status_history';

    protected $fillable = ['reservation_id', 'from_status', 'to_status', 'actor_id', 'reason', 'request_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
