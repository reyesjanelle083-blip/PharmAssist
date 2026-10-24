<?php

namespace App\Http\Requests;

use App\Support\UserContext;
use Illuminate\Foundation\Http\FormRequest;

class SyncInventoryRequest extends FormRequest
{
    /**
     * Defence in depth: the service re-checks the branch itself. A branch manager may only sync the branch in
     * X-User-Branch; the CEO may sync any branch. Runs before validation, so another branch's data never leaks as a 422.
     */
    public function authorize(): bool
    {
        $user = $this->attributes->get(UserContext::ATTRIBUTE);
        $branch = $this->input('branch_id');

        if (! $user instanceof UserContext) {
            return false;
        }

        // A missing or non-numeric branch_id is reported by validation (422), not as a permission problem.
        return ! (is_int($branch) || (is_string($branch) && ctype_digit($branch))) || $user->canAccessBranch((int) $branch);
    }

    /** Only the shape is checked here; each row is judged separately by InventorySyncService. */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'strict' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['array'],
        ];
    }
}
