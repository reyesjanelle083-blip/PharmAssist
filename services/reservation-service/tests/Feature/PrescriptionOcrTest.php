<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

/** Plan M7: reservation-service calling integration-service's OCR for a prescription photo, wired in Phase 6. */
class PrescriptionOcrTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    private const URL = '/internal/v1/reservations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
        $this->medicines[3]['rx'] = true;
    }

    private function createWithPhoto(string $key = 'ocr-key-00001'): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 3, 'quantity' => 1]],
            'prescription' => $this->png(),
        ], $this->customer($key));
    }

    private function ocrCalls(): array
    {
        return $this->sent('/ocr/prescription', 'POST');
    }

    // ------------------------------------------------------------ the happy paths

    public function test_a_successful_reading_is_stored_with_the_reservation_and_returned(): void
    {
        $this->ocrMode = 'success';

        $response = $this->createWithPhoto()->assertStatus(201)
            ->assertJsonPath('data.ocr_status', 'success')
            ->assertJsonPath('data.ocr_text', 'Amoxicillin 500mg - take twice daily for 7 days')
            ->assertJsonPath('warnings', []);

        $reservation = Reservation::firstOrFail();
        $this->assertSame('success', $reservation->ocr_status);
        $this->assertSame('Amoxicillin 500mg - take twice daily for 7 days', $reservation->ocr_text);
        $this->assertEqualsWithDelta(0.93, (float) $reservation->ocr_confidence, 0.001);

        // confidence is an internal detail: stored, but not part of the public answer
        $this->assertArrayNotHasKey('ocr_confidence', $response->json('data'));
    }

    public function test_a_low_confidence_reading_still_succeeds_with_its_own_status(): void
    {
        $this->ocrMode = 'low_confidence';

        $this->createWithPhoto()->assertStatus(201)
            ->assertJsonPath('data.ocr_status', 'low_confidence')
            ->assertJsonPath('data.ocr_text', 'Amox1cillin 5OOmg');

        $this->assertSame('low_confidence', Reservation::firstOrFail()->ocr_status);
    }

    // ------------------------------------------------------------ OCR unavailable never fails or corrupts the reservation

    public function test_integration_service_answering_unavailable_still_creates_the_reservation(): void
    {
        $this->ocrMode = 'unavailable';

        $this->createWithPhoto()->assertStatus(201)
            ->assertJsonPath('data.ocr_status', 'unavailable')
            ->assertJsonPath('data.ocr_text', null)
            ->assertJsonPath('data.prescription_uploaded', true)
            ->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);

        $reservation = Reservation::firstOrFail();
        $this->assertSame('unavailable', $reservation->ocr_status);
        $this->assertNull($reservation->ocr_text);
        $this->assertNull($reservation->ocr_confidence);
        $this->assertSame('pending', $reservation->status, 'the reservation itself is unaffected');
    }

    public function test_integration_service_unreachable_still_creates_the_reservation_and_keeps_the_stock_hold(): void
    {
        $this->ocrMode = 'down';

        $this->createWithPhoto()->assertStatus(201)->assertJsonPath('data.ocr_status', 'unavailable');

        $this->assertCount(1, $this->holdCalls());
        $this->assertSame([], $this->releaseCalls(), 'a slow or down OCR provider must never give the stock hold back');
    }

    public function test_integration_service_timing_out_still_creates_the_reservation(): void
    {
        $this->ocrMode = 'timeout';

        $this->createWithPhoto()->assertStatus(201)->assertJsonPath('data.ocr_status', 'unavailable');
        $this->assertSame([], $this->releaseCalls());
    }

    public function test_an_unreadable_answer_from_integration_service_still_creates_the_reservation(): void
    {
        $this->ocrMode = 'garbage';

        $this->createWithPhoto()->assertStatus(201)->assertJsonPath('data.ocr_status', 'unavailable');
    }

    public function test_integration_url_not_configured_still_creates_the_reservation_and_makes_no_call(): void
    {
        config(['reservation.integration_url' => null]);

        $this->createWithPhoto()->assertStatus(201)->assertJsonPath('data.ocr_status', 'unavailable');
        $this->assertSame([], $this->ocrCalls());
    }

    // ------------------------------------------------------------ nothing provider-specific ever leaks

    public function test_the_response_never_mentions_the_ocr_provider(): void
    {
        $this->ocrMode = 'success';

        $raw = $this->createWithPhoto()->assertStatus(201)->getContent();
        $this->assertStringNotContainsString('tesseract', $raw);
        $this->assertStringNotContainsString('source', $raw);
    }

    public function test_an_unavailable_reading_never_exposes_why_in_the_response(): void
    {
        $this->ocrMode = 'down';

        $raw = $this->createWithPhoto()->assertStatus(201)->getContent();
        $this->assertStringNotContainsString('cURL', $raw);
        $this->assertStringNotContainsString('ConnectionException', $raw);
        $this->assertStringNotContainsString('integration.test', $raw);
    }

    // ------------------------------------------------------------ the call itself

    public function test_the_internal_key_and_request_id_are_sent_and_the_image_is_attached(): void
    {
        $this->ocrMode = 'success';

        $this->createWithPhoto()->assertStatus(201)->assertHeader('X-Request-Id');

        $calls = $this->ocrCalls();
        $this->assertCount(1, $calls);
        /** @var Request $call */
        $call = $calls[0];
        $this->assertSame([$this->internalKey], $call->header('X-Internal-Key'));
        $this->assertTrue($call->hasFile('image'));
    }

    public function test_only_one_ocr_call_is_made_even_with_two_prescription_items(): void
    {
        $this->medicines[1]['rx'] = true; // both items now need a prescription, same one photo
        $this->ocrMode = 'success';

        $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 1, 'quantity' => 1], ['medicine_id' => 3, 'quantity' => 1]],
            'prescription' => $this->png(),
        ], $this->customer())->assertStatus(201)->assertJsonPath('data.ocr_status', 'success');

        $this->assertCount(1, $this->ocrCalls());
    }

    // ------------------------------------------------------------ idempotency

    public function test_replaying_the_same_key_does_not_call_ocr_again(): void
    {
        $this->ocrMode = 'success';

        $first = $this->createWithPhoto('ocr-replay-key')->assertStatus(201);
        $second = $this->createWithPhoto('ocr-replay-key')->assertStatus(201);

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Reservation::count());
        $this->assertCount(1, $this->ocrCalls());
    }

    public function test_replaying_an_unavailable_reading_still_carries_its_warning(): void
    {
        $this->ocrMode = 'down';

        $first = $this->createWithPhoto('ocr-replay-key-2')->assertStatus(201)->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);
        $second = $this->createWithPhoto('ocr-replay-key-2')->assertStatus(201)->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);

        $this->assertSame($first->json('data'), $second->json('data'));
    }
}
