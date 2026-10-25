<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The caller identity forwarded by the gateway (X-User-Id, X-User-Role, X-User-Branch).
 * The gateway builds these only from a verified JWT; the service re-checks permissions with them
 * (defense in depth) because it must never trust that the gateway was the only caller.
 */
final class UserContext
{
    public const ATTRIBUTE = 'user_context';

    public const ROLES = ['customer', 'pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo'];

    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly ?int $branchId,
    ) {}

    /** Returns null when the identity headers are missing or malformed. */
    public static function fromRequest(Request $request): ?self
    {
        $id = $request->headers->get('X-User-Id');
        $role = $request->headers->get('X-User-Role');
        $branch = $request->headers->get('X-User-Branch');

        if (! is_string($id) || ! ctype_digit($id) || ! is_string($role) || ! in_array($role, self::ROLES, true)) {
            return null;
        }

        if ($branch !== null && $branch !== '' && ! ctype_digit($branch)) {
            return null;
        }

        return new self((int) $id, $role, ($branch === null || $branch === '') ? null : (int) $branch);
    }

    /** @param list<string> $roles */
    public function hasRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** The CEO may use any branch; every other role only its own branch. */
    public function canAccessBranch(int $branchId): bool
    {
        return $this->role === 'ceo' || ($this->branchId !== null && $this->branchId === $branchId);
    }
}
