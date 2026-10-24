<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a user. Never exposes password_hash, id_document_path, lock or failure counters.
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'contact_no' => $this->contact_no,
            'role' => $this->role,
            'branch_id' => $this->branch_id,
            'email_verified' => $this->email_verified_at !== null,
            'verification_status' => $this->verification_status,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
