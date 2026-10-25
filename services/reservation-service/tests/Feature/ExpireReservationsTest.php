<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class ExpireReservationsTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expire(int $exitCode = 0): void
    {
        $this->artisan('reservations:expire')->assertExitCode($exitCode);
    }

    public function test_pending_and_approved_reservations_expire_after_their_time_and_not_before(): void
    {
        $pending = $this->makeReservation(7, 1, 'PA-PENDING1', 'pending', now()->addHours(48));
        $approved = $this->makeReservation(7, 1, 'PA-APPROVED', 'approved', now()->addHours(48));

        Carbon::setTestNow(now()->addHours(47));
        $this->expire();
        $this->assertSame(['pending', 'approved'], [$pending->fresh()->status, $approved->fresh()->status]);
        $this->assertSame([], $this->sent('/release'));

        Carbon::setTestNow(now()->addHours(2)); // 49 h after creation
        $this->expire();

        $this->assertSame(['expired', 'expired'], [$pending->fresh()->status, $approved->fresh()->status]);
        $this->assertCount(2, $this->sent('/release'));
        $this->assertStringContainsString($pending->hold_ref, $this->sent('/release')[0]->url());
    }

    public function test_the_history_row_has_no_actor_and_the_reason_auto_expiry(): void
    {
        $r = $this->makeReservation(7, 1, 'PA-APPROVED', 'approved', now()->subMinute());

        $this->expire();

        $row = ReservationStatusHistory::where('reservation_id', $r->id)->orderByDesc('id')->first();
        $this->assertSame(['approved', 'expired', null, 'auto-expiry'], [$row->from_status, $row->to_status, $row->actor_id, $row->reason]);
        $this->assertStringStartsWith('expire-', $row->request_id);
    }

    public function test_finished_reservations_and_ones_not_yet_due_are_left_alone(): void
    {
        foreach (['completed', 'rejected', 'cancelled', 'expired'] as $i => $status) {
            $this->makeReservation(7, 1, 'PA-FINISH0'.$i, $status, now()->subDay());
        }
        $future = $this->makeReservation(7, 1, 'PA-FUTURE01', 'pending', now()->addMinute());
        $edge = $this->makeReservation(7, 1, 'PA-EDGE0001', 'pending', now()); // expires_at == now is not yet in the past

        $this->expire();

        $this->assertSame([], $this->sent());
        $this->assertSame(['pending', 'pending'], [$future->fresh()->status, $edge->fresh()->status]);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->makeReservation(7, 1, 'PA-PENDING1', 'pending', now()->subHour());

        $this->expire();
        $this->expire();

        $this->assertCount(1, $this->sent('/release'));
        $this->assertSame(1, ReservationStatusHistory::where('to_status', 'expired')->count());
    }

    public function test_one_failure_does_not_stop_the_others_and_is_picked_up_by_the_next_run(): void
    {
        $a = $this->makeReservation(7, 1, 'PA-AAAAAAA1', 'pending', now()->subHour());
        $bad = $this->makeReservation(7, 1, 'PA-BADONE01', 'approved', now()->subHour());
        $c = $this->makeReservation(7, 1, 'PA-CCCCCCC1', 'pending', now()->subHour());
        $this->failReleaseFor = [$bad->hold_ref];

        $this->expire(1); // exit code 1: one failed

        $this->assertSame('expired', $a->fresh()->status);
        $this->assertSame('approved', $bad->fresh()->status, 'the failing one is unchanged');
        $this->assertSame('expired', $c->fresh()->status);

        $this->failReleaseFor = [];
        $this->expire();
        $this->assertSame('expired', $bad->fresh()->status);
    }

    public function test_a_hold_that_cannot_be_released_keeps_the_reservation_and_fails_the_run(): void
    {
        $r = $this->makeReservation(7, 1, 'PA-COMMITT1', 'approved', now()->subHour());
        $this->releaseMode = 'committed';

        $this->expire(1);

        $this->assertSame('approved', $r->fresh()->status);
    }

    public function test_more_than_one_chunk_is_processed(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->makeReservation(7, 1, sprintf('PA-MANY%04d', $i), $i % 2 ? 'pending' : 'approved', now()->subHour());
        }

        $this->expire();

        $this->assertSame(120, Reservation::where('status', 'expired')->count());
        $this->assertCount(120, $this->sent('/release'));
    }

    public function test_it_is_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'reservations:expire'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
