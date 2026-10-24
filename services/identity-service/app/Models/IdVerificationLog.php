<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdVerificationLog extends Model
{
    // Only created_at exists on this table.
    const UPDATED_AT = null;

    protected $table = 'id_verification_log';

    protected $fillable = ['user_id', 'reviewer_id', 'decision', 'reason'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
