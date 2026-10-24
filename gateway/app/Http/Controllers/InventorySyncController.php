<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateJwt;
use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;

/**
 * Aggregator for the branch inventory feed (plan F7):
 *   1. forward the raw feed to integration-service, which validates and translates it to normalised JSON;
 *   2. if that fails, answer with its error envelope unchanged;
 *   3. check the branch scope from the VERIFIED token (a manager may only sync their own branch);
 *   4. send the normalised JSON to inventory-service, together with the verified user headers;
 *   5. answer with the inventory envelope.
 * The client's headers are never forwarded; the identity always comes from the token.
 */
class InventorySyncController extends Controller
{
    private const MAX_BODY_BYTES = 512 * 1024;
    private const ALLOWED_TYPES = ['application/xml', 'text/xml', 'application/json'];

    public function __construct(private readonly ServiceClient $services) {}

    public function sync(Request $request): JsonResponse
    {
        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return Envelope::error('UNSUPPORTED_MEDIA_TYPE', 'The feed must be sent as application/xml, text/xml or application/json.', 415);
        }

        // Refuse oversize bodies without reading them when the client announces the size, and again after reading.
        $declared = $request->header('Content-Length');
        $body = null;
        if ((is_numeric($declared) && (int) $declared > self::MAX_BODY_BYTES) || strlen($body = $request->getContent()) > self::MAX_BODY_BYTES) {
            return Envelope::error('PAYLOAD_TOO_LARGE', 'The feed is larger than '.(self::MAX_BODY_BYTES / 1024).' KB.', 413);
        }

        $strict = filter_var($request->query('strict', 'false'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($strict === null) {
            return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, ['strict' => ['The strict parameter must be true or false.']]);
        }

        $timeout = (float) config('services.integration.sync_timeout');

        // (a) translate. Only the normalised content type is forwarded, never the client's header value.
        $translated = $this->services->postRaw('integration', '/internal/v1/inventory-feed/translate', $body ?? $request->getContent(), $type, $timeout);

        // (b) a refused feed is answered with the translator's own envelope
        if ($translated->getStatusCode() !== 200) {
            return $translated;
        }

        $feed = $translated->getData(true)['data'] ?? null;
        if (! is_array($feed) || ! is_int($feed['branch_id'] ?? null) || ! is_array($feed['items'] ?? null)) {
            return Envelope::error('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        // (c) branch scope, from the verified token: the CEO may sync any branch, everyone else only their own
        $claims = $request->attributes->get(AuthenticateJwt::ATTRIBUTE);
        if ($claims['role'] !== 'ceo' && ($claims['branch_id'] === null || $claims['branch_id'] !== $feed['branch_id'])) {
            return Envelope::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        // (d) + (e) apply, and answer with inventory-service's envelope
        return $this->services->postJson('inventory', '/internal/v1/inventory/sync', [
            'branch_id' => $feed['branch_id'],
            'strict' => $strict,
            'items' => $feed['items'],
        ], $timeout);
    }
}
