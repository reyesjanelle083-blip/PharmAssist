<?php

namespace App\Http\Controllers;

use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public catalog: forwards to inventory-service. No token needed; no user identity is sent. */
class CatalogController extends Controller
{
    public function __construct(private readonly ServiceClient $services) {}

    public function medicines(Request $request): JsonResponse
    {
        // Only the documented filters are forwarded; the downstream service validates them.
        return $this->services->get('inventory', '/internal/v1/medicines', $request->only(['q', 'category', 'rx', 'branch_id', 'include', 'page', 'per_page']));
    }

    public function medicine(int $id): JsonResponse
    {
        return $this->services->get('inventory', '/internal/v1/medicines/'.$id);
    }

    public function categories(): JsonResponse
    {
        return $this->services->get('inventory', '/internal/v1/medicines/categories');
    }

    public function counseling(int $id): JsonResponse
    {
        return $this->services->get('inventory', '/internal/v1/medicines/'.$id.'/counseling');
    }

    public function branches(): JsonResponse
    {
        return $this->services->get('inventory', '/internal/v1/branches');
    }
}
