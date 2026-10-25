<?php

namespace Tests\Concerns;

use App\Models\Reservation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

trait ReservationTestHelpers
{
    protected string $internalKey = 'reservation-test-key';

    /** 1x1 PNG: real image bytes, so the content-based MIME check passes without needing GD. */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * What the fake inventory-service knows and does. Change these in a test before calling the endpoint.
     *
     * @var array<int, array{rx: bool, price: string}> medicine_id => data; a missing id is unknown (404)
     */
    protected array $medicines = [
        1 => ['rx' => false, 'price' => '12.50'],
        2 => ['rx' => false, 'price' => '8.00'],
        3 => ['rx' => true, 'price' => '30.25'],
    ];

    /** Every request the service made, including those the fake answered with a connection error (Http::recorded() skips those). @var list<Request> */
    protected array $calls = [];

    /** ok | insufficient | down | timeout | garbage | not_found */
    protected string $holdMode = 'ok';

    /** How many release calls fail (connection error) before one succeeds. PHP_INT_MAX = always fail. */
    protected int $releaseFailures = 0;

    protected function setUpReservation(): void
    {
        config([
            'pharmassist.internal_key' => $this->internalKey,
            'pharmassist.service' => 'reservation-service',
            'reservation.inventory_url' => 'http://inventory.test',
            'reservation.release_pause_ms' => 0,
            'reservation.ttl_hours' => 48,
        ]);
        Storage::fake('local');
        $this->fakeInventory();
    }

    /** @return array<string, string> */
    protected function as(string $role = 'customer', ?int $branchId = null, int $userId = 7, array $extra = []): array
    {
        return array_merge([
            'X-Internal-Key' => $this->internalKey,
            'X-User-Id' => (string) $userId,
            'X-User-Role' => $role,
            'X-User-Branch' => $branchId === null ? '' : (string) $branchId,
            'Accept' => 'application/json',
        ], $extra);
    }

    /** @return array<string, string> */
    protected function customer(string $key = 'key-0000-0001', int $userId = 7, array $extra = []): array
    {
        return $this->as('customer', null, $userId, ['Idempotency-Key' => $key] + $extra);
    }

    /** @param array<string, mixed> $override */
    protected function body(array $override = []): array
    {
        return array_replace([
            'branch_id' => 1,
            'items' => [['medicine_id' => 1, 'quantity' => 2], ['medicine_id' => 2, 'quantity' => 1]],
            'notes' => 'Pick up after 5 pm',
        ], $override);
    }

    protected function png(string $name = 'rx.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    /** @return list<Request> */
    protected function sent(string $contains = '', ?string $method = null): array
    {
        $requests = [];
        foreach ($this->calls as $request) {
            if (str_contains($request->url(), $contains) && ($method === null || $request->method() === $method)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    protected function holdCalls(): array
    {
        return array_values(array_filter($this->sent('/stock/holds', 'POST'), fn (Request $r) => ! str_ends_with($r->url(), '/release')));
    }

    protected function releaseCalls(): array
    {
        return $this->sent('/release', 'POST');
    }

    protected function envelope(mixed $data, int $status = 200, ?array $error = null): array
    {
        return ['status' => $status, 'service' => 'inventory-service', 'request_id' => 'inv-1', 'data' => $error ? null : $data, 'error' => $error, 'warnings' => []];
    }

    /** One fake for the whole test (a second Http::fake() would not replace this one). Reads the properties above on each call. */
    protected function fakeInventory(): void
    {
        $failedReleases = 0;

        Http::fake(function (Request $request) use (&$failedReleases) {
            $this->calls[] = $request;
            $url = $request->url();

            if (preg_match('#/internal/v1/medicines/(\d+)$#', $url, $m)) {
                $id = (int) $m[1];
                if (! isset($this->medicines[$id])) {
                    return Http::response($this->envelope(null, 404, ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => null]), 404);
                }

                return Http::response($this->envelope(['id' => $id, 'generic_name' => "Med {$id}", 'prescription_required' => $this->medicines[$id]['rx']]));
            }

            if (str_ends_with($url, '/release')) {
                if ($failedReleases < $this->releaseFailures) {
                    $failedReleases++;
                    throw new ConnectionException('cURL error 7: Failed to connect to inventory.test');
                }

                return Http::response($this->envelope(['status' => 'released']));
            }

            if (str_ends_with($url, '/internal/v1/stock/holds')) {
                $body = $request->data();

                return match ($this->holdMode) {
                    'down' => throw new ConnectionException('cURL error 7: Failed to connect to inventory.test'),
                    'timeout' => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'),
                    'garbage' => Http::response('<html>Fatal error in /var/www/app.php</html>', 200),
                    'insufficient' => Http::response($this->envelope(null, 409, [
                        'code' => 'STOCK_INSUFFICIENT',
                        'message' => 'Not enough stock available for every requested item.',
                        'details' => ['items' => [['medicine_id' => 1, 'requested' => 2, 'available' => 1, 'sufficient' => false]]],
                    ]), 409),
                    'not_found' => Http::response($this->envelope(null, 404, ['code' => 'NOT_FOUND', 'message' => 'One or more medicines are not stocked at this branch.', 'details' => ['medicine_ids' => [1]]]), 404),
                    default => Http::response($this->envelope([
                        'reservation_ref' => $body['reservation_ref'],
                        'branch_id' => $body['branch_id'],
                        'status' => 'held',
                        'expires_at' => now()->addMinutes($body['ttl_minutes'])->toIso8601String(),
                        'items' => array_map(fn (array $i) => [
                            'medicine_id' => $i['medicine_id'],
                            'medicine_name' => "Med {$i['medicine_id']} 500mg Tablet",
                            'unit_price' => $this->medicines[$i['medicine_id']]['price'],
                            'prescription_required' => $this->medicines[$i['medicine_id']]['rx'],
                            'quantity' => $i['quantity'],
                            'status' => 'held',
                        ], $body['items']),
                    ]), 201),
                };
            }

            return Http::response('unexpected', 500);
        });
    }

    protected function makeReservation(int $userId = 7, int $branchId = 1, string $code = 'PA-AAAAAAAA'): Reservation
    {
        $reservation = Reservation::create([
            'claim_code' => $code,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'status' => 'pending',
            'ocr_status' => 'not_required',
            'hold_ref' => (string) \Illuminate\Support\Str::uuid(),
            'expires_at' => now()->addHours(48),
        ]);
        $reservation->items()->create(['medicine_id' => 1, 'medicine_name' => 'Med 1', 'unit_price' => '12.50', 'quantity' => 2]);
        $reservation->history()->create(['from_status' => null, 'to_status' => 'pending', 'actor_id' => $userId, 'request_id' => 'r-1', 'created_at' => now()]);

        return $reservation;
    }
}
