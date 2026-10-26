<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'email',
        'password_hash',
        'role',
        'first_name',
        'last_name',
        'contact_no',
        'branch_id',
        'email_verified_at',
        'verification_status', // legacy (old ID-approval flow), unused; rejection_reason and id_document_path are legacy too and not fillable
        'is_active',
        'failed_logins',
        'locked_until',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** The password column is named password_hash, not password. */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }
}
