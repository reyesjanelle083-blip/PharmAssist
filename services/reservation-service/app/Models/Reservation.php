<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    // The file path is private: it must never be serialised into an API answer.
    protected $hidden = ['prescription_path'];

    protected $fillable = [
        'claim_code',
        'user_id',
        'branch_id',
        'status',
        'ocr_status',
        'ocr_text',
        'ocr_confidence',
        'prescription_path',
        'hold_ref',
        'notes',
        'expires_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'ocr_confidence' => 'decimal:3',
            'expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservationItem::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->orderBy('id');
    }
}
