<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class ReservationStatusTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    private const OWNER = 7;

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

    private function change(Reservation $r, array $body, array $headers)
    {
        return $this->patchJson("/internal/v1/reservations/{$r->id}/status", $body, $headers);
    }

    private function staff(string $role = 'pharmacy_technician', int $branch = 1, int $id = 20, array $extra = []): array
    {
        return $this->as($role, $branch, $id, $extra);
    }

    private function owner(array $extra = []): array
    {
        return $this->as('customer', null, self::OWNER, $extra);
    }

    /** @return list<string> the last path segment of each commit/release call */
    private function inventoryActions(): array
    {
        return array_map(fn ($r) => basename($r->url()), array_merge($this->sent('/commit'), $this->sent('/release')));
    }

    private function assertNoInventoryCall(): void
    {
        $this->assertSame([], array_merge($this->sent('/commit'), $this->sent('/release')));
    }

    // ------------------------------------------------------------ valid transitions

    public function test_approve_changes_the_status_writes_history_and_calls_nothing_in_inventory(): void
    {
        foreach (['pharmacy_technician', 'branch_manager'] as $i => $role) {
            $r = $this->makeReservation(self::OWNER, 1, 'PA-AAAAAAA'.$i);

            $this->change($r, ['action' => 'approve'], $this->staff($role, 1, 20 + $i, ['X-Request-Id' => "req-approve-{$i}"]))
                ->assertOk()
                ->assertJsonPath('data.status', 'approved')
                ->assertJsonPath('data.history.1.from_status', 'pending')
                ->assertJsonPath('data.history.1.to_status', 'approved');

            $row = ReservationStatusHistory::where('reservation_id', $r->id)->orderByDesc('id')->first();
            $this->assertSame([20 + $i, 'pending', 'approved', "req-approve-{$i}"], [$row->actor_id, $row->from_status, $row->to_status, $row->request_id]);
        }

        $this->assertSame([], $this->sent(), 'approve does not call inventory-service at all');
    }

    public function test_reject_needs_a_reason_and_releases_the_hold(): void
    {
        $r = $this->makeReservation();

        $this->change($r, ['action' => 'reject'], $this->staff())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->change($r, ['action' => 'reject', 'reason' => '  '], $this->staff())->assertStatus(422);
        $this->assertNoInventoryCall();

        $this->change($r, ['action' => 'reject', 'reason' => 'Prescription is unreadable'], $this->staff('branch_manager', 1, 21))
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.history.1.reason', 'Prescription is unreadable');

        $this->assertSame([['release']], [$this->inventoryActions()]);
        $this->assertStringContainsString($r->hold_ref, $this->sent('/release')[0]->url());
        $this->assertSame('rejected', $r->fresh()->status);
    }

    public function test_the_owner_can_cancel_a_pending_and_an_approved_reservation(): void
    {
        $pending = $this->makeReservation(self::OWNER, 1, 'PA-PENDING1');
        $approved = $this->makeReservation(self::OWNER, 1, 'PA-APPROVED', 'approved');

        $this->change($pending, ['action' => 'cancel'], $this->owner())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->change($approved, ['action' => 'cancel', 'reason' => 'Changed my mind'], $this->owner())->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(['release', 'release'], $this->inventoryActions());
        $this->assertSame('pending', ReservationStatusHistory::where('reservation_id', $pending->id)->orderByDesc('id')->value('from_status'));
        $this->assertSame('approved', ReservationStatusHistory::where('reservation_id', $approved->id)->orderByDesc('id')->value('from_status'));
    }

    public function test_complete_commits_the_hold_for_technician_assistant_and_manager(): void
    {
        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $i => $role) {
            $r = $this->makeReservation(self::OWNER, 1, 'PA-COMPLET'.$i, 'approved');

            $this->change($r, ['action' => 'complete', 'claim_code' => $r->claim_code], $this->staff($role, 1, 30 + $i))
                ->assertOk()->assertJsonPath('data.status', 'completed');
        }

        $this->assertSame(['commit', 'commit', 'commit'], $this->inventoryActions());
    }

    // ------------------------------------------------------------ claim code

    public function test_the_claim_code_is_trimmed_and_case_insensitive(): void
    {
        $r = $this->makeReservation(self::OWNER, 1, 'PA-7K3Q9X2M', 'approved');

        $this->change($r, ['action' => 'complete', 'claim_code' => '  pa-7k3q9x2m '], $this->staff('pharmacy_assistant'))
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_wrong_claim_code_is_422_never_reveals_the_right_one_and_changes_nothing(): void
    {
        $r = $this->makeReservation(self::OWNER, 1, 'PA-7K3Q9X2M', 'approved');

        foreach (['PA-7K3Q9X2N', 'PA-7K3Q9X2', 'pa-', '7K3Q9X2M', 'PA-7K3Q9X2MX'] as $wrong) {
            $response = $this->change($r, ['action' => 'complete', 'claim_code' => $wrong], $this->staff())
                ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CLAIM_CODE');
            $this->assertStringNotContainsString('7K3Q9X2M', $response->getContent());
        }

        $this->change($r, ['action' => 'complete'], $this->staff())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertNoInventoryCall();
        $this->assertSame('approved', $r->fresh()->status);
    }

    public function test_completing_after_expiry_is_409_reservation_expired(): void
    {
        $r = $this->makeReservation(self::OWNER, 1, 'PA-7K3Q9X2M', 'approved', now()->addHours(48));

        Carbon::setTestNow(now()->addHours(47));
        $this->change($r, ['action' => 'complete', 'claim_code' => 'PA-7K3Q9X2M'], $this->staff())->assertOk();

        $r2 = $this->makeReservation(self::OWNER, 1, 'PA-EXPIRED2', 'approved', now()->addHours(1));
        Carbon::setTestNow(now()->addHours(2));
        $this->change($r2, ['action' => 'complete', 'claim_code' => 'PA-EXPIRED2'], $this->staff())
            ->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_EXPIRED');

        $this->assertSame(['commit'], $this->inventoryActions(), 'only the first one reached inventory');
        $this->assertSame('approved', $r2->fresh()->status);
    }

    // ------------------------------------------------------------ invalid transitions

    public function test_every_move_that_is_not_listed_is_409_with_the_current_status(): void
    {
        $allowed = [
            'approve' => ['pending'],
            'reject' => ['pending'],
            'cancel' => ['pending', 'approved'],
            'complete' => ['approved'],
        ];
        $statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled', 'expired'];
        $n = 0;

        foreach ($allowed as $action => $from) {
            foreach (array_diff($statuses, $from) as $status) {
                $r = $this->makeReservation(self::OWNER, 1, sprintf('PA-INVALID%d', $n++), $status);
                $headers = $action === 'cancel' ? $this->owner() : $this->staff();

                $this->change($r, ['action' => $action, 'reason' => 'x', 'claim_code' => $r->claim_code], $headers)
                    ->assertStatus(409)
                    ->assertJsonPath('error.code', 'INVALID_TRANSITION')
                    ->assertJsonPath('error.details.current_status', $status);

                $this->assertSame($status, $r->fresh()->status, "{$action} on {$status}");
            }
        }

        $this->assertGreaterThan(15, $n);
        $this->assertSame([], $this->sent(), 'an invalid move never reaches inventory-service');
        $this->assertSame(0, ReservationStatusHistory::where('to_status', '!=', 'pending')->count());
    }

    public function test_repeating_an_action_after_success_is_409_and_calls_inventory_only_once(): void
    {
        $a = $this->makeReservation(self::OWNER, 1, 'PA-REPEAT01');
        $this->change($a, ['action' => 'approve'], $this->staff())->assertOk();
        $this->change($a, ['action' => 'approve'], $this->staff())->assertStatus(409)->assertJsonPath('error.details.current_status', 'approved');

        $b = $this->makeReservation(self::OWNER, 1, 'PA-REPEAT02');
        $this->change($b, ['action' => 'cancel'], $this->owner())->assertOk();
        $this->change($b, ['action' => 'cancel'], $this->owner())->assertStatus(409)->assertJsonPath('error.details.current_status', 'cancelled');

        $c = $this->makeReservation(self::OWNER, 1, 'PA-REPEAT03', 'approved');
        $this->change($c, ['action' => 'complete', 'claim_code' => 'PA-REPEAT03'], $this->staff())->assertOk();
        $this->change($c, ['action' => 'complete', 'claim_code' => 'PA-REPEAT03'], $this->staff())->assertStatus(409);

        $this->assertSame(['commit', 'release'], $this->inventoryActions(), 'one commit and one release in total (commits are listed first)');
        $this->assertSame(1, ReservationStatusHistory::where('reservation_id', $c->id)->where('to_status', 'completed')->count());
    }

    // ------------------------------------------------------------ roles, branches, owner

    public function test_roles_that_may_not_do_an_action_are_403_and_nothing_changes(): void
    {
        $denied = [
            'approve' => ['customer', 'pharmacy_assistant', 'ceo'],
            'reject' => ['customer', 'pharmacy_assistant', 'ceo'],
            'cancel' => ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo'],
            'complete' => ['customer', 'ceo'],
        ];

        $n = 0;
        foreach ($denied as $action => $roles) {
            foreach ($roles as $role) {
                $status = $action === 'complete' ? 'approved' : 'pending';
                $r = $this->makeReservation(self::OWNER, 1, sprintf('PA-DENIED%02d', $n++), $status);
                $headers = $role === 'customer' ? $this->owner() : $this->as($role, $role === 'ceo' ? null : 1, 40);

                $this->change($r, ['action' => $action, 'reason' => 'x', 'claim_code' => $r->claim_code], $headers)
                    ->assertStatus(403, "{$role} must not {$action}")->assertJsonPath('error.code', 'FORBIDDEN');
                $this->assertSame($status, $r->fresh()->status);
            }
        }

        $this->assertSame([], $this->sent());
    }

    public function test_staff_of_another_branch_or_without_a_branch_are_403(): void
    {
        $pending = $this->makeReservation(self::OWNER, 1, 'PA-OTHERBR1');
        $approved = $this->makeReservation(self::OWNER, 1, 'PA-OTHERBR2', 'approved');

        foreach (['pharmacy_technician', 'branch_manager'] as $role) {
            $this->change($pending, ['action' => 'approve'], $this->staff($role, 2))->assertStatus(403);
            $this->change($pending, ['action' => 'reject', 'reason' => 'x'], $this->staff($role, 2))->assertStatus(403);
            $this->change($pending, ['action' => 'approve'], $this->as($role, null, 20))->assertStatus(403);
        }
        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->change($approved, ['action' => 'complete', 'claim_code' => 'PA-OTHERBR2'], $this->staff($role, 2))->assertStatus(403);
        }

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    public function test_only_the_owner_can_cancel(): void
    {
        $r = $this->makeReservation(self::OWNER, 1);

        $this->change($r, ['action' => 'cancel'], $this->as('customer', null, 8))->assertStatus(403);
        $this->change($r, ['action' => 'cancel'], $this->as('customer', 1, 8))->assertStatus(403);

        $this->assertSame('pending', $r->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    public function test_unknown_reservation_is_404_bad_action_is_422_missing_identity_is_401(): void
    {
        $r = $this->makeReservation(self::OWNER, 1);

        $this->patchJson('/internal/v1/reservations/9999/status', ['action' => 'approve'], $this->staff())->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->change($r, ['action' => 'explode'], $this->staff())->assertStatus(422);
        $this->change($r, [], $this->staff())->assertStatus(422);

        $this->change($r, ['action' => 'approve'], ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'])->assertStatus(401);
        $this->change($r, ['action' => 'approve'], ['X-Internal-Key' => 'wrong'] + $this->staff())->assertStatus(401);
        $this->patchJson("/internal/v1/reservations/{$r->id}/status", ['action' => 'approve'], ['Accept' => 'application/json'])->assertStatus(401);
        $this->assertSame('pending', $r->fresh()->status);
    }

    // ------------------------------------------------------------ inventory failures

    public function test_an_inventory_failure_leaves_the_status_and_history_unchanged(): void
    {
        $cases = [
            'release down' => ['releaseMode', 'down', 'reject', 503, 'SERVICE_UNAVAILABLE'],
            'release timeout' => ['releaseMode', 'timeout', 'cancel', 504, 'UPSTREAM_TIMEOUT'],
            'release garbage' => ['releaseMode', 'garbage', 'reject', 502, 'BAD_UPSTREAM_RESPONSE'],
            'commit down' => ['commitMode', 'down', 'complete', 503, 'SERVICE_UNAVAILABLE'],
            'commit timeout' => ['commitMode', 'timeout', 'complete', 504, 'UPSTREAM_TIMEOUT'],
            'commit garbage' => ['commitMode', 'garbage', 'complete', 502, 'BAD_UPSTREAM_RESPONSE'],
        ];
        $n = 0;

        foreach ($cases as $label => [$property, $mode, $action, $http, $code]) {
            $this->{$property} = $mode;
            $status = $action === 'complete' ? 'approved' : 'pending';
            $r = $this->makeReservation(self::OWNER, 1, 'PA-FAILURE'.$n++, $status);
            $headers = $action === 'cancel' ? $this->owner() : $this->staff();

            $response = $this->change($r, ['action' => $action, 'reason' => 'x', 'claim_code' => $r->claim_code], $headers)
                ->assertStatus($http)->assertJsonPath('error.code', $code);

            $this->assertStringNotContainsString('inventory.test', $response->getContent(), $label);
            $this->assertSame($status, $r->fresh()->status, $label);
            $this->assertSame(1, ReservationStatusHistory::where('reservation_id', $r->id)->count(), $label);
            $this->{$property} = 'ok';
        }
    }

    public function test_a_retry_after_an_inventory_failure_succeeds(): void
    {
        $r = $this->makeReservation();
        $this->commitMode = 'ok';
        $this->releaseMode = 'timeout';

        $this->change($r, ['action' => 'reject', 'reason' => 'x'], $this->staff())->assertStatus(504);
        $this->releaseMode = 'ok';
        $this->change($r, ['action' => 'reject', 'reason' => 'x'], $this->staff())->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_commit_that_finds_the_holds_already_committed_counts_as_done(): void
    {
        $r = $this->makeReservation(self::OWNER, 1, 'PA-REPAIR01', 'approved');
        $this->commitMode = 'already_committed'; // an earlier attempt committed, then crashed before the status update

        $this->change($r, ['action' => 'complete', 'claim_code' => 'PA-REPAIR01'], $this->staff())
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, ReservationStatusHistory::where('reservation_id', $r->id)->where('to_status', 'completed')->count());
    }

    public function test_a_hold_in_another_state_is_409_hold_not_available_and_logged(): void
    {
        Log::spy();
        $a = $this->makeReservation(self::OWNER, 1, 'PA-HOLDNA01', 'approved');
        $b = $this->makeReservation(self::OWNER, 1, 'PA-HOLDNA02', 'approved');
        $c = $this->makeReservation(self::OWNER, 1, 'PA-HOLDNA03', 'approved');
        $d = $this->makeReservation(self::OWNER, 1, 'PA-HOLDNA04');

        $this->commitMode = 'released'; // commit on a released or expired hold
        $this->change($a, ['action' => 'complete', 'claim_code' => 'PA-HOLDNA01'], $this->staff(), )->assertStatus(409)->assertJsonPath('error.code', 'HOLD_NOT_AVAILABLE');

        $this->commitMode = 'not_found';
        $this->change($b, ['action' => 'complete', 'claim_code' => 'PA-HOLDNA02'], $this->staff())->assertStatus(409)->assertJsonPath('error.code', 'HOLD_NOT_AVAILABLE');

        $this->releaseMode = 'committed'; // release on an already committed hold
        $this->change($d, ['action' => 'reject', 'reason' => 'x'], $this->staff())->assertStatus(409)->assertJsonPath('error.code', 'HOLD_NOT_AVAILABLE');

        foreach ([$a, $b, $c, $d] as $r) {
            $this->assertNotContains($r->fresh()->status, ['completed', 'rejected']);
        }
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx = []) => $m === 'Stock hold not available for this action' && isset($ctx['reservation_id'], $ctx['action']))->times(3);
    }

    public function test_releasing_a_hold_that_no_longer_exists_counts_as_done(): void
    {
        $r = $this->makeReservation();
        $this->releaseMode = 'not_found';

        $this->change($r, ['action' => 'reject', 'reason' => 'x'], $this->staff())->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    // ------------------------------------------------------------ database failure after inventory

    public function test_a_database_failure_after_inventory_is_logged_critical_500_and_a_retry_succeeds(): void
    {
        Log::spy();
        $r = $this->makeReservation(self::OWNER, 1, 'PA-CRASH001', 'approved');
        $failing = true;
        ReservationStatusHistory::creating(function () use (&$failing) {
            if ($failing) {
                throw new \RuntimeException('SQLSTATE secret detail');
            }
        });

        $response = $this->change($r, ['action' => 'complete', 'claim_code' => 'PA-CRASH001'], $this->staff('pharmacy_technician', 1, 20, ['X-Request-Id' => 'req-crash-1']))
            ->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame('approved', $r->fresh()->status, 'the status update was rolled back with the history row');
        $this->assertSame(1, ReservationStatusHistory::where('reservation_id', $r->id)->count());
        Log::shouldHaveReceived('critical')->withArgs(fn (string $m, array $ctx = []) => $ctx['request_id'] === 'req-crash-1' && $ctx['reservation_id'] === $r->id)->once();

        // The retry: inventory now answers "already committed" (it was committed the first time), and the action completes.
        $failing = false;
        $this->commitMode = 'already_committed';
        $this->change($r, ['action' => 'complete', 'claim_code' => 'PA-CRASH001'], $this->staff())->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_competing_change_during_the_inventory_call_makes_the_guard_fail_with_409(): void
    {
        $r = $this->makeReservation();
        $this->onInventoryCall = function () use ($r) {
            Reservation::whereKey($r->id)->update(['status' => 'approved']); // another request approved it meanwhile
        };

        $this->change($r, ['action' => 'reject', 'reason' => 'x'], $this->staff())
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION')->assertJsonPath('error.details.current_status', 'approved');

        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(1, ReservationStatusHistory::where('reservation_id', $r->id)->count(), 'the losing request writes no history');
    }
}
