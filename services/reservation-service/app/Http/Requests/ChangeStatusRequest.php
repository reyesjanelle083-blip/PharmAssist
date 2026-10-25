<?php

namespace App\Http\Requests;

use App\Services\ReservationStatusService;
use App\Support\UserContext;
use Illuminate\Foundation\Http\FormRequest;

class ChangeStatusRequest extends FormRequest
{
    /**
     * A role that may never perform the requested action is refused (403) before the body is validated.
     * Branch and ownership are checked by the service, which knows the reservation.
     */
    public function authorize(): bool
    {
        $user = $this->attributes->get(UserContext::ATTRIBUTE);
        if (! $user instanceof UserContext) {
            return false;
        }

        $roles = ReservationStatusService::rolesFor((string) $this->input('action'));

        // An unknown action has no roles: let validation report it (422) instead of a misleading 403.
        return $roles === [] || $user->hasRole($roles);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject,cancel,complete'],
            // reservation_status_history.reason is 255 characters
            'reason' => ['nullable', 'string', 'max:255', 'required_if:action,reject'],
            'claim_code' => ['nullable', 'string', 'max:32', 'required_if:action,complete'],
        ];
    }
}
