<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use App\Services\Feed\FeedException;
use App\Services\Feed\InventoryFeedTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PharmAssist\Common\Http\Envelope;
use Throwable;

/** Service-to-service (through the gateway). Protected by InternalKey. Translation only: no writes, no outbound calls. */
class InventoryFeedController extends Controller
{
    public function __construct(private readonly InventoryFeedTranslator $translator) {}

    public function translate(Request $request): JsonResponse
    {
        $started = microtime(true);
        $contentType = (string) $request->header('Content-Type', '');
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $operation = $type === 'application/json' ? 'translate_json' : 'translate_xml';

        try {
            $result = $this->translator->translate($request->getContent(), $contentType);
        } catch (FeedException $e) {
            $this->log($request, $e->operation, 'failure', $e->status, $started, $e->reason);

            throw $e;
        }

        $this->log($request, $operation, 'success', 200, $started, null);

        return Envelope::success($result);
    }

    /** One row per feed, with only a status and a fixed reason: never the feed, its values or any file name. */
    private function log(Request $request, string $operation, string $outcome, int $status, float $started, ?string $reason): void
    {
        try {
            IntegrationLog::create([
                'request_id' => $request->attributes->get('request_id'),
                'provider' => 'xml_feed',
                'operation' => $operation,
                'status_code' => $status,
                'outcome' => $outcome,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_message' => $reason,
            ]);
        } catch (Throwable) {
            Log::warning('integration_logs write failed.');
        }
    }
}
