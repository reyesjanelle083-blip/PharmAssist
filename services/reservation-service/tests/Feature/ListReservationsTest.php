<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class ListReservationsTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    private const URL = '/internal/v1/reservations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** user 7 and 8, branches 1 and 2, created one minute apart so the order is known. */
    private function seedReservations(): array
    {
        $made = [];
        foreach ([[7, 1, 'pending'], [7, 2, 'approved'], [8, 1, 'pending'], [8, 2, 'completed'], [7, 1, 'cancelled']] as $i => [$user, $branch, $status]) {
            Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00')->addMinutes($i));
            $made[] = $this->makeReservation($user, $branch, 'PA-LIST000'.$i, $status);
        }
        Carbon::setTestNow();

        return $made;
    }

    private function ids(array $headers, string $query = ''): array
    {
        return array_column($this->getJson(self::URL.$query, $headers)->assertOk()->json('data.items'), 'id');
    }

    public function test_a_customer_sees_only_their_own_newest_first(): void
    {
        [$r0, $r1, $r2, $r3, $r4] = $this->seedReservations();

        $this->assertSame([$r4->id, $r1->id, $r0->id], $this->ids($this->as('customer', null, 7)));
        $this->assertSame([$r3->id, $r2->id], $this->ids($this->as('customer', null, 8)));
        $this->assertSame([], $this->ids($this->as('customer', null, 99)));
        $this->assertSame([$r4->id, $r1->id, $r0->id], $this->ids($this->as('customer', null, 7), '?branch_id=2'), 'branch_id is ignored for customers');
    }

    public function test_staff_see_only_their_branch(): void
    {
        [$r0, $r1, $r2, $r3, $r4] = $this->seedReservations();

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->assertSame([$r4->id, $r2->id, $r0->id], $this->ids($this->as($role, 1, 20)), "{$role} branch 1");
            $this->assertSame([$r3->id, $r1->id], $this->ids($this->as($role, 2, 21)), "{$role} branch 2");
        }
        $this->assertSame([$r4->id, $r2->id, $r0->id], $this->ids($this->as('branch_manager', 1, 20), '?branch_id=2'), 'a staff member cannot look at another branch');
    }

    public function test_staff_without_a_branch_are_403(): void
    {
        $this->getJson(self::URL, $this->as('pharmacy_technician', null, 20))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_the_ceo_sees_everything_and_can_filter_by_branch(): void
    {
        [$r0, $r1, $r2, $r3, $r4] = $this->seedReservations();
        $ceo = $this->as('ceo', null, 30);

        $this->assertSame([$r4->id, $r3->id, $r2->id, $r1->id, $r0->id], $this->ids($ceo));
        $this->assertSame([$r3->id, $r1->id], $this->ids($ceo, '?branch_id=2'));
        $this->assertSame([], $this->ids($ceo, '?branch_id=9'));
    }

    public function test_the_status_filter(): void
    {
        [$r0, , $r2] = $this->seedReservations();

        $this->assertSame([$r2->id, $r0->id], $this->ids($this->as('ceo', null, 30), '?status=pending'));
        $this->getJson(self::URL.'?status=nonsense', $this->as('ceo', null, 30))->assertStatus(422);
    }

    public function test_pagination(): void
    {
        $this->seedReservations();
        $ceo = $this->as('ceo', null, 30);

        $first = $this->getJson(self::URL.'?per_page=2', $ceo)->assertOk()
            ->assertJsonPath('data.meta', ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
        $this->assertCount(2, $first->json('data.items'));

        $last = $this->getJson(self::URL.'?per_page=2&page=3', $ceo)->assertOk()->assertJsonPath('data.meta.page', 3);
        $this->assertCount(1, $last->json('data.items'));
        $this->assertCount(0, $this->getJson(self::URL.'?per_page=2&page=4', $ceo)->json('data.items'));

        $this->getJson(self::URL, $ceo)->assertJsonPath('data.meta.per_page', 20);
        $this->getJson(self::URL.'?per_page=50', $ceo)->assertOk();
        $this->getJson(self::URL.'?per_page=51', $ceo)->assertStatus(422);
        $this->getJson(self::URL.'?per_page=0', $ceo)->assertStatus(422);
        $this->getJson(self::URL.'?page=0', $ceo)->assertStatus(422);
    }

    public function test_each_entry_has_the_summary_fields_and_nothing_private(): void
    {
        $r = $this->makeReservation(7, 1, 'PA-SUMMARY1');
        $r->items()->create(['medicine_id' => 2, 'medicine_name' => 'Med 2', 'unit_price' => '8.00', 'quantity' => 3]);
        $r->update(['prescription_path' => 'prescriptions/private-name.png', 'ocr_text' => 'Amoxicillin 500 mg', 'ocr_status' => 'success', 'notes' => 'my note']);

        $response = $this->getJson(self::URL, $this->as('customer', null, 7))->assertOk();
        $entry = $response->json('data.items.0');

        $this->assertSame(['id', 'claim_code', 'status', 'branch_id', 'expires_at', 'created_at', 'item_count', 'total_price'], array_keys($entry));
        $this->assertSame(['PA-SUMMARY1', 'pending', 1, 2, '49.00'], [$entry['claim_code'], $entry['status'], $entry['branch_id'], $entry['item_count'], $entry['total_price']]); // 12.50 x 2 + 8.00 x 3
        $this->assertStringNotContainsString('private-name', $response->getContent());
        $this->assertStringNotContainsString('Amoxicillin', $response->getContent());
        $this->assertStringNotContainsString('prescription', $response->getContent());
    }

    public function test_a_reservation_without_items_has_a_zero_total(): void
    {
        Reservation::create(['claim_code' => 'PA-NOITEMS1', 'user_id' => 7, 'branch_id' => 1, 'status' => 'pending', 'ocr_status' => 'not_required', 'hold_ref' => 'h-1', 'expires_at' => now()->addDay()]);

        $this->getJson(self::URL, $this->as('customer', null, 7))->assertOk()->assertJsonPath('data.items.0.item_count', 0)->assertJsonPath('data.items.0.total_price', '0.00');
    }

    public function test_identity_and_internal_key_are_required(): void
    {
        $this->getJson(self::URL, ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'])->assertStatus(401);
        $this->getJson(self::URL, ['X-Internal-Key' => 'wrong'] + $this->as('ceo', null, 30))->assertStatus(401);
        $this->getJson(self::URL, ['Accept' => 'application/json'])->assertStatus(401);
    }
}
