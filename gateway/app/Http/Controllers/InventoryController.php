<?php

namespace App\Http\Controllers;

use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff inventory routes. Role checks happen in the route middleware (auth.jwt + role);
 * inventory-service re-checks role and branch from the identity headers the ServiceClient derives from the token.
 */
class InventoryController extends Controller
{
    private const UPDATE_FIELDS = ['stock_quantity', 'price', 'min_threshold', 'batch_no', 'expiration_date', 'version'];

    public function __construct(private readonly ServiceClient $services) {}

    public function index(Request $request, int $id): JsonResponse
    {
        return $this->services->get('inventory', "/internal/v1/branches/{$id}/inventory", $request->only(['status', 'page', 'per_page']));
    }

    public function update(Request $request, int $id, int $medicineId): JsonResponse
    {
        // Forward only the editable fields: never reserved_quantity, status or anything else the client sends.
        return $this->services->putJson('inventory', "/internal/v1/branches/{$id}/inventory/{$medicineId}", $request->only(self::UPDATE_FIELDS));
    }
}
