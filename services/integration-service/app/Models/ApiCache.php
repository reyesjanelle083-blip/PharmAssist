<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiCache extends Model
{
    public $timestamps = false;

    protected $table = 'api_cache';

    protected $fillable = ['cache_key', 'payload', 'expires_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
