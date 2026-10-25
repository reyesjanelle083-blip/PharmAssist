<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class PrescriptionStreamTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
    }

    private function url(Reservation|int $r): string
    {
        return '/internal/v1/reservations/'.($r instanceof Reservation ? $r->id : $r).'/prescription';
    }

    private function withFile(string $content, string $storedAs = 'prescriptions/abc123.png', int $owner = 7, int $branch = 1): Reservation
    {
        Storage::disk('local')->put($storedAs, $content);
        static $n = 0;
        $r = $this->makeReservation($owner, $branch, sprintf('PA-FILE%04d', ++$n));
        $r->update(['prescription_path' => $storedAs]);

        return $r;
    }

    public function test_the_owner_gets_the_image_with_safe_headers(): void
    {
        $r = $this->withFile(base64_decode(self::PNG));

        $response = $this->get($this->url($r), $this->as('customer', null, 7))->assertOk();

        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=prescription.png', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('abc123', json_encode($response->headers->all()), 'the stored name is never exposed');
    }

    public function test_the_type_comes_from_the_content_not_from_the_stored_name(): void
    {
        $jpeg = $this->withFile(base64_decode(self::JPEG), 'prescriptions/looks-like-a.png');
        $png = $this->withFile(base64_decode(self::PNG), 'prescriptions/looks-like-a.txt');

        $a = $this->get($this->url($jpeg), $this->as('customer', null, 7))->assertOk();
        $this->assertSame('image/jpeg', $a->headers->get('Content-Type'));
        $this->assertSame('inline; filename=prescription.jpg', $a->headers->get('Content-Disposition'));

        $b = $this->get($this->url($png), $this->as('customer', null, 7))->assertOk();
        $this->assertSame('image/png', $b->headers->get('Content-Type'));
        $this->assertSame('inline; filename=prescription.png', $b->headers->get('Content-Disposition'));
    }

    public function test_same_branch_staff_and_the_ceo_can_see_it(): void
    {
        $r = $this->withFile(base64_decode(self::PNG));

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->get($this->url($r), $this->as($role, 1, 20))->assertOk();
        }
        $this->get($this->url($r), $this->as('ceo', null, 30))->assertOk();
    }

    public function test_everyone_else_is_403(): void
    {
        $r = $this->withFile(base64_decode(self::PNG));

        $this->getJson($this->url($r), $this->as('customer', null, 8))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson($this->url($r), $this->as('customer', 1, 8))->assertStatus(403);
        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->getJson($this->url($r), $this->as($role, 2, 20))->assertStatus(403);
            $this->getJson($this->url($r), $this->as($role, null, 20))->assertStatus(403);
        }
    }

    public function test_no_file_or_an_unusable_file_is_404(): void
    {
        $none = $this->makeReservation(7, 1, 'PA-NOFILE01');
        $missing = $this->makeReservation(7, 1, 'PA-MISSING1');
        $missing->update(['prescription_path' => 'prescriptions/gone.png']);
        $text = $this->withFile('<?php echo "not an image";', 'prescriptions/x.png');
        $gif = $this->withFile(base64_decode('R0lGODlhAQABAAAAACwAAAAAAQABAAACAkQBADs='), 'prescriptions/y.png');

        foreach ([$none, $missing, $text, $gif] as $r) {
            $this->getJson($this->url($r), $this->as('customer', null, 7))->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->getJson($this->url(9999), $this->as('customer', null, 7))->assertStatus(404);
    }

    public function test_a_stranger_cannot_tell_whether_a_file_exists(): void
    {
        $with = $this->withFile(base64_decode(self::PNG));
        $without = $this->makeReservation(7, 1, 'PA-NOFILE02');

        $this->getJson($this->url($with), $this->as('customer', null, 8))->assertStatus(403);
        $this->getJson($this->url($without), $this->as('customer', null, 8))->assertStatus(403);
    }

    public function test_identity_and_internal_key_are_required(): void
    {
        $r = $this->withFile(base64_decode(self::PNG));

        $this->getJson($this->url($r), ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'])->assertStatus(401);
        $this->getJson($this->url($r), ['X-Internal-Key' => 'wrong'] + $this->as('customer', null, 7))->assertStatus(401);
    }

    public function test_the_stored_path_never_appears_in_the_reservation_answers(): void
    {
        $r = $this->withFile(base64_decode(self::PNG), 'prescriptions/secret-path-1234.png');

        foreach ([$this->getJson('/internal/v1/reservations/'.$r->id, $this->as('customer', null, 7)), $this->getJson('/internal/v1/reservations', $this->as('customer', null, 7))] as $response) {
            $this->assertStringNotContainsString('secret-path-1234', $response->getContent());
        }
    }
}
