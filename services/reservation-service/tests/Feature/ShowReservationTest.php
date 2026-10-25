<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class ShowReservationTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
    }

    private function show(int $id, array $headers)
    {
        return $this->getJson("/internal/v1/reservations/{$id}", $headers);
    }

    public function test_the_owner_sees_the_reservation_with_items_and_status_history(): void
    {
        $reservation = $this->makeReservation(7, 1);
        $reservation->update(['prescription_path' => 'prescriptions/secret-name.png']);

        $response = $this->show($reservation->id, $this->as('customer', null, 7))
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.claim_code', 'PA-AAAAAAAA')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.prescription_uploaded', true)
            ->assertJsonPath('data.items.0.medicine_name', 'Med 1')
            ->assertJsonPath('data.items.0.unit_price', '12.50')
            ->assertJsonPath('data.history.0.from_status', null)
            ->assertJsonPath('data.history.0.to_status', 'pending');

        $this->assertStringNotContainsString('secret-name', $response->getContent());
        $this->assertStringNotContainsString('prescription_path', $response->getContent());
    }

    public function test_staff_of_the_same_branch_and_the_ceo_can_see_it(): void
    {
        $reservation = $this->makeReservation(7, 1);

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->show($reservation->id, $this->as($role, 1, 20))->assertOk()->assertJsonPath('data.id', $reservation->id);
        }
        $this->show($reservation->id, $this->as('ceo', null, 30))->assertOk();
    }

    public function test_staff_of_another_branch_are_403(): void
    {
        $reservation = $this->makeReservation(7, 1);

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->show($reservation->id, $this->as($role, 2, 20))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }
        $this->show($reservation->id, $this->as('pharmacy_technician', null, 20))->assertStatus(403); // staff without a branch
    }

    public function test_another_customer_is_403_even_from_the_same_branch_number(): void
    {
        $reservation = $this->makeReservation(7, 1);

        $this->show($reservation->id, $this->as('customer', null, 8))->assertStatus(403);
        $this->show($reservation->id, $this->as('customer', 1, 8))->assertStatus(403); // a customer header branch grants nothing
    }

    public function test_an_unknown_id_is_404_for_every_role(): void
    {
        $this->show(9999, $this->as('customer', null, 7))->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->show(9999, $this->as('branch_manager', 1, 20))->assertStatus(404);
        $this->show(9999, $this->as('ceo', null, 30))->assertStatus(404);
    }

    public function test_missing_identity_or_internal_key_is_401(): void
    {
        $reservation = $this->makeReservation(7, 1);

        $this->show($reservation->id, ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->show($reservation->id, ['X-Internal-Key' => 'wrong'] + $this->as('customer', null, 7))->assertStatus(401);
        $this->getJson("/internal/v1/reservations/{$reservation->id}", ['Accept' => 'application/json'])->assertStatus(401);
        $this->show($reservation->id, $this->as('customer', null, 7, ['X-User-Id' => 'x']))->assertStatus(401);
    }

    public function test_a_created_reservation_can_be_read_back_by_its_owner(): void
    {
        $id = $this->postJson('/internal/v1/reservations', $this->body(), $this->customer())->assertStatus(201)->json('data.id');

        $this->show($id, $this->as('customer', null, 7))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.reason', null);

        $this->assertSame(1, Reservation::count());
    }

    public function test_a_non_numeric_id_is_not_routed(): void
    {
        $this->getJson('/internal/v1/reservations/abc', $this->as('customer', null, 7))->assertStatus(404);
    }
}
