<?php

namespace App\Http\Controllers;

use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use PharmAssist\Common\Http\Envelope;

/**
 * Reservation routes. The gateway authenticates (auth.jwt), checks the role, rate limits, and forwards only what the
 * reservation-service documents. Identity (X-User-*) is derived from the verified token by ServiceClient; the only
 * client header passed on is Idempotency-Key. reservation-service re-checks role, branch and ownership itself.
 */
class ReservationController extends Controller
{
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;

    private const STATUS_FIELDS = ['action', 'reason', 'claim_code'];

    private const LIST_FILTERS = ['status', 'page', 'per_page', 'branch_id'];

    public function __construct(private readonly ServiceClient $services) {}

    public function store(Request $request): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return Envelope::error('IDEMPOTENCY_KEY_REQUIRED', 'The Idempotency-Key header is required.', 400);
        }

        $timeout = $this->timeout();
        $headers = ['Idempotency-Key' => $key];

        // JSON: re-send only the documented fields.
        if ($request->isJson()) {
            return $this->services->postJson('reservation', '/internal/v1/reservations', $this->fields($request->json()->all()), $timeout, $headers);
        }

        $type = strtolower((string) $request->header('Content-Type', ''));
        if (! str_starts_with($type, 'multipart/form-data') && ! str_starts_with($type, 'application/x-www-form-urlencoded')) {
            return Envelope::error('UNSUPPORTED_MEDIA_TYPE', 'Send the reservation as JSON or multipart form data.', 415);
        }

        // Multipart: the optional prescription image, checked here for size before it is forwarded.
        $files = [];
        $file = $request->file('prescription');
        if ($file !== null) {
            if (! $file instanceof UploadedFile) {
                return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, ['prescription' => ['The prescription must be a single file.']]);
            }
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->tooLarge(); // PHP refused it for being over its own upload limit
            }
            if (! $file->isValid()) {
                return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, ['prescription' => ['The prescription could not be uploaded.']]);
            }
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                return $this->tooLarge();
            }
            $files['prescription'] = $file;
        } elseif ($request->input('prescription') !== null) {
            return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, ['prescription' => ['The prescription must be a file.']]);
        }

        // Nested items are sent as items[0][medicine_id], items[0][quantity]; the file goes on as upload.<ext>.
        return $this->services->postMultipart(
            'reservation',
            '/internal/v1/reservations',
            $this->flatten($this->fields($request->except('prescription'))),
            $files,
            $timeout,
            anonymiseFileNames: true,
            clientHeaders: $headers,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = [];
        foreach (self::LIST_FILTERS as $name) {
            $value = $request->query($name);
            if (is_scalar($value)) {
                $query[$name] = $value;
            }
        }

        return $this->services->get('reservation', '/internal/v1/reservations', $query);
    }

    public function show(int $id): JsonResponse
    {
        return $this->services->get('reservation', "/internal/v1/reservations/{$id}");
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $body = $request->json()->all() ?: $request->post();

        return $this->services->patchJson(
            'reservation',
            "/internal/v1/reservations/{$id}/status",
            array_intersect_key($body, array_flip(self::STATUS_FIELDS)),
            $this->timeout(),
        );
    }

    /** Binary, not an envelope: see ServiceClient::stream(). */
    public function prescription(int $id): JsonResponse|Response
    {
        return $this->services->stream('reservation', "/internal/v1/reservations/{$id}/prescription");
    }

    // ------------------------------------------------------------------ helpers

    private function timeout(): float
    {
        return (float) config('services.reservation.timeout');
    }

    private function tooLarge(): JsonResponse
    {
        return Envelope::error('PAYLOAD_TOO_LARGE', 'The prescription image must be 4 MB or smaller.', 413);
    }

    /**
     * Only branch_id, notes and items[].medicine_id/quantity are passed on, whatever else the client sent.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fields(array $input): array
    {
        $out = [];
        foreach (['branch_id', 'notes'] as $name) {
            if (array_key_exists($name, $input) && (is_scalar($input[$name]) || $input[$name] === null)) {
                $out[$name] = $input[$name];
            }
        }

        if (array_key_exists('items', $input)) {
            $items = $input['items'];
            $out['items'] = is_array($items)
                ? array_map(fn ($item) => is_array($item) ? array_intersect_key($item, array_flip(['medicine_id', 'quantity'])) : $item, $items)
                : $items;
        }

        return $out;
    }

    /**
     * Turns {"items": [{"medicine_id": 1}]} into ["items[0][medicine_id]" => 1], the multipart form field naming.
     *
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $flat += $this->flatten($value, $name);
            } else {
                $flat[$name] = is_bool($value) ? (int) $value : $value;
            }
        }

        return $flat;
    }
}
