<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'key', 'request_hash', 'status_code', 'response_json', 'created_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status_code' => 'integer',
            'response_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
