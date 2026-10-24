<?php

namespace Tests\Feature;

use App\Models\ApiCache;
use App\Models\IntegrationLog;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrService;
use App\Services\Ocr\ProcessOutcome;
use App\Services\Ocr\ProcessRunner;
use App\Services\Ocr\SymfonyProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * The Tesseract process is always faked (FakeProcessRunner); images are generated with GD.
 * Run with the GD extension enabled: php -d extension=gd vendor/bin/phpunit
 */
class OcrTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'integration-test-key';
    private const URL = '/internal/v1/ocr/prescription';

    private FakeProcessRunner $runner;

    /** @var list<string> */
    private array $tempUploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('The GD extension is required (run: php -d extension=gd vendor/bin/phpunit).');
        }

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'integration-service',
            'integration.mode' => 'live',
            'integration.allow_stub_in_production' => false,
            'integration.ocr.provider' => 'tesseract',
            'integration.ocr.tesseract_bin' => 'tesseract',
            'integration.ocr.psm' => 6,
            'integration.ocr.timeout_seconds' => 15,
            'integration.ocr.min_confidence' => 0.6,
            'integration.ocr.max_concurrent' => 2,
            'integration.ocr.force_fail' => false,
        ]);

        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
    }

    // ------------------------------------------------------------ helpers

    private function headers(): array
    {
        return ['X-Internal-Key' => self::KEY, 'Accept' => 'application/json'];
    }

    private function image(int $width = 600, int $height = 300, string $format = 'png', string $name = 'prescription.png'): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 230, 200, 120)); // not gray, so grayscale conversion is visible
        imagestring($img, 5, 10, 10, 'Take 1 capsule three times a day', imagecolorallocate($img, 10, 10, 10));

        ob_start();
        $format === 'jpg' ? imagejpeg($img) : imagepng($img);
        $bytes = (string) ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * A genuine uploaded-file object backed by a real temp file. Laravel's fake files guess the MIME type from the
     * file NAME, which would hide exactly what these tests are about: the real type is sniffed from the content.
     */
    private function upload(string $bytes, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ocrtest');
        file_put_contents($path, $bytes);
        $this->tempUploads[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function imageBytes(int $width = 400, int $height = 200, string $format = 'png'): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 230, 200, 120));
        ob_start();
        $format === 'jpg' ? imagejpeg($img) : imagepng($img);

        return (string) ob_get_clean();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempUploads as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function send(?UploadedFile $file = null, array $headers = [])
    {
        return $this->post(self::URL, ['image' => $file ?? $this->image()], $headers + $this->headers());
    }

    private function tempFiles(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'pharmassist-ocr-*.png') ?: [];
    }

    private function lastLog(): IntegrationLog
    {
        return IntegrationLog::where('provider', 'ocr')->latest('id')->firstOrFail();
    }

    private function failWith(ProcessOutcome $outcome): void
    {
        $this->runner->outcome = $outcome;
    }

    // ------------------------------------------------------------ authentication and shape

    public function test_a_missing_or_wrong_internal_key_returns_401(): void
    {
        $this->post(self::URL, ['image' => $this->image()], ['Accept' => 'application/json'])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->post(self::URL, ['image' => $this->image()], ['X-Internal-Key' => 'wrong', 'Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame(0, $this->runner->count());
    }

    public function test_a_successful_read_returns_the_contract_shape(): void
    {
        $response = $this->send()->assertOk()
            ->assertJsonStructure(['status', 'service', 'request_id', 'data' => ['ocr_text', 'confidence', 'ocr_status', 'source'], 'error', 'warnings'])
            ->assertJsonPath('service', 'integration-service')
            ->assertJsonPath('data.ocr_status', 'success')
            ->assertJsonPath('data.source', 'tesseract')
            ->assertJsonPath('error', null)
            ->assertJsonPath('warnings', []);

        $this->assertSame("Take 1 capsule\nthree times a day", $response->json('data.ocr_text'));
        $this->assertSame(['confidence', 'ocr_status', 'ocr_text', 'source'], collect(array_keys($response->json('data')))->sort()->values()->all());
    }

    // ------------------------------------------------------------ TSV parsing and the confidence maths

    public function test_confidence_is_the_mean_of_word_confidences_ignoring_minus_one(): void
    {
        // 96, 90, 84 and 70 average 85; the structural rows and the empty word (all -1) must not count.
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([
            ['Amoxicillin', 96, 1], ['500', 90, 1], ['mg', 84, 1], ['capsule', 70, 2],
        ])));

        $response = $this->send()->assertOk();

        $this->assertEqualsWithDelta(0.85, $response->json('data.confidence'), 0.0001);
        $this->assertSame("Amoxicillin 500 mg\ncapsule", $response->json('data.ocr_text'));
        $this->assertSame('success', $response->json('data.ocr_status'));
    }

    public function test_words_keep_their_line_structure_and_reading_order(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([
            ['Rx:', 90, 1], ['Losartan', 92, 1], ['50mg', 93, 1],
            ['Take', 94, 2], ['once', 95, 2], ['daily', 96, 2],
            ['Refill', 90, 3], ['x2', 90, 3],
        ])));

        $this->assertSame("Rx: Losartan 50mg\nTake once daily\nRefill x2", $this->send()->assertOk()->json('data.ocr_text'));
    }

    public function test_a_tsv_without_a_header_is_treated_as_a_failure(): void
    {
        $this->failWith(new ProcessOutcome(0, 'this is not tsv at all'));

        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable');
        $this->assertSame('parse_error', $this->lastLog()->error_message);
    }

    public function test_short_text_is_low_confidence_even_when_the_engine_is_sure(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([['Rx', 99, 1], ['5mg', 98, 1]])));

        $response = $this->send()->assertOk()
            ->assertJsonPath('data.ocr_status', 'low_confidence')
            ->assertJsonPath('data.ocr_text', 'Rx 5mg');

        $this->assertContains('TEXT_TOO_SHORT', $response->json('warnings'));
        $this->assertNotContains('LOW_CONFIDENCE_HANDWRITING_LIKELY', $response->json('warnings'));
    }

    public function test_low_engine_confidence_is_low_confidence_with_the_handwriting_warning(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([
            ['Amoxicilin', 41, 1], ['5O0', 38, 1], ['capsuIe', 52, 1], ['tak3', 35, 2], ['twice', 60, 2], ['dayly', 44, 2],
        ])));

        $response = $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'low_confidence');

        $this->assertLessThan(0.6, $response->json('data.confidence'));
        $this->assertContains('LOW_CONFIDENCE_HANDWRITING_LIKELY', $response->json('warnings'));
        $this->assertNotNull($response->json('data.ocr_text'));
        $this->assertSame('success', $this->lastLog()->outcome, 'a poor reading is still a completed attempt');
    }

    public function test_an_image_without_text_is_low_confidence_with_no_confidence_value(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([])));

        $response = $this->send()->assertOk()
            ->assertJsonPath('data.ocr_status', 'low_confidence')
            ->assertJsonPath('data.ocr_text', '')
            ->assertJsonPath('data.confidence', null);

        $this->assertContains('NO_TEXT_FOUND', $response->json('warnings'));
    }

    // ------------------------------------------------------------ provider failures are never a 5xx

    public function test_a_missing_binary_returns_unavailable_not_a_500(): void
    {
        $this->runner->executable = null;

        $this->send()->assertOk()
            ->assertJsonPath('data.ocr_status', 'unavailable')
            ->assertJsonPath('data.ocr_text', null)
            ->assertJsonPath('data.confidence', null)
            ->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);

        $this->assertSame(0, $this->runner->count(), 'no process is started without a binary');
        $this->assertSame(['failure', 'binary_not_found'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
    }

    public function test_a_timeout_returns_unavailable(): void
    {
        $this->failWith(new ProcessOutcome(-1, '', '', true));

        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable')->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);

        $this->assertSame(['timeout', 'timeout'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
        $this->assertEqualsWithDelta(15.0, $this->runner->runs[0]['timeout'], 0.001, 'OCR_TIMEOUT_SECONDS is passed to the process');
    }

    public function test_a_non_zero_exit_returns_unavailable_and_never_leaks_process_output(): void
    {
        $this->failWith(new ProcessOutcome(1, 'SECRET-STDOUT-TEXT', 'SECRET-STDERR Error opening data file /home/patient'));

        $response = $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable')->assertJsonPath('data.ocr_text', null);

        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('SECRET', json_encode(IntegrationLog::all()->toArray()));
        $this->assertSame(['failure', 'nonzero_exit'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
    }

    public function test_the_command_is_an_argument_array_with_no_user_value_in_it(): void
    {
        config(['integration.ocr.tesseract_bin' => 'tesseract', 'integration.ocr.psm' => 4]);
        $this->send($this->image(name: 'weird name; rm -rf --no-preserve-root.png'))->assertOk();

        $command = $this->runner->runs[0]['command'];

        $this->assertSame('/fake/tesseract', $command[0]);
        $this->assertMatchesRegularExpression('#pharmassist-ocr-[0-9a-f]{32}\.png$#', $command[1]);
        $this->assertSame(['stdout', '-l', 'eng', '--psm', '4', 'tsv'], array_slice($command, 2));
        $this->assertStringNotContainsString('weird', implode(' ', $command), 'the upload name never reaches the command');
        foreach ($command as $part) {
            $this->assertIsString($part);
        }

        config(['integration.ocr.psm' => 99]); // out of range falls back to 6
        $this->send()->assertOk();
        $this->assertSame('6', $this->runner->runs[1]['command'][6]);
    }

    // ------------------------------------------------------------ preprocessing

    public function test_an_oversize_image_is_downscaled_before_ocr_and_converted_to_gray(): void
    {
        $this->send($this->image(3600, 2400))->assertOk();

        $run = $this->runner->runs[0];
        $this->assertTrue($run['image_exists']);
        $this->assertSame(2000, max($run['width'], $run['height']), 'longest side is at most 2000 px');
        $this->assertSame([2000, 1333], [$run['width'], $run['height']], 'aspect ratio is kept');
        $this->assertTrue($run['gray'], 'the OCR copy is grayscale');
    }

    public function test_a_small_image_is_not_upscaled(): void
    {
        $this->send($this->image(800, 500, 'jpg', 'photo.jpg'))->assertOk();

        $this->assertSame([800, 500], [$this->runner->runs[0]['width'], $this->runner->runs[0]['height']]);
    }

    public function test_a_gd_failure_means_unavailable_with_its_own_warning(): void
    {
        $this->app->bind(OcrService::class, fn ($app) => new class($app->make(OcrProvider::class), $app->make(\App\Services\Ocr\OcrCircuitBreaker::class)) extends OcrService
        {
            protected function gdAvailable(): bool
            {
                return false;
            }
        });

        $this->send()->assertOk()
            ->assertJsonPath('data.ocr_status', 'unavailable')
            ->assertJsonPath('warnings', ['OCR_UNAVAILABLE', 'IMAGE_PROCESSING_UNAVAILABLE']);

        $this->assertSame(0, $this->runner->count());
        $this->assertSame([], $this->tempFiles());
    }

    // ------------------------------------------------------------ upload validation (422)

    public function test_a_non_image_renamed_to_jpg_is_rejected_with_422(): void
    {
        $fake = $this->upload("This is a text file, not an image.\n", 'prescription.jpg');

        $this->send($fake)->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['image']]]);

        $this->assertSame(0, $this->runner->count());
        $this->assertSame([], $this->tempFiles());
    }

    public function test_other_file_types_pdf_gif_and_html_are_rejected_whatever_they_are_called(): void
    {
        $gif = imagecreatetruecolor(50, 50);
        ob_start();
        imagegif($gif);
        $gifBytes = (string) ob_get_clean();

        foreach ([
            'a gif named .png' => $this->upload($gifBytes, 'scan.png'),
            'a pdf' => $this->upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'scan.jpg'),
            'html' => $this->upload('<html><script>alert(1)</script></html>', 'scan.png'),
            'empty file' => $this->upload('', 'scan.png'),
        ] as $name => $file) {
            $this->send($file)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, $this->runner->count());
    }

    public function test_a_real_image_with_the_wrong_extension_is_accepted(): void
    {
        $this->send($this->upload($this->imageBytes(), 'scan.txt'))->assertOk()->assertJsonPath('data.ocr_status', 'success');
        $this->send($this->upload($this->imageBytes(400, 200, 'jpg'), 'scan.png'))->assertOk()->assertJsonPath('data.ocr_status', 'success'); // a JPEG named .png
    }

    public function test_a_corrupt_image_is_rejected(): void
    {
        $png = "\x89PNG\r\n\x1a\n".random_bytes(200); // a PNG signature followed by garbage

        $this->send($this->upload($png, 'scan.png'))->assertStatus(422);
        $this->assertSame([], $this->tempFiles());
    }

    public function test_size_and_pixel_limits_are_enforced(): void
    {
        // over 5 MB
        $this->send(UploadedFile::fake()->create('huge.png', 6000, 'image/png'))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // wider than 4000 px (a small, cheap file)
        $this->send($this->image(4001, 10))->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['image']]]);
        $this->send($this->image(10, 4001))->assertStatus(422);

        // exactly 4000 px is accepted
        $this->send($this->image(4000, 20))->assertOk();

        $this->assertSame(1, $this->runner->count());
    }

    public function test_a_missing_image_field_returns_422(): void
    {
        $this->post(self::URL, [], $this->headers())->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['image']]]);
        $this->post(self::URL, ['image' => 'not a file'], $this->headers())->assertStatus(422);
    }

    // ------------------------------------------------------------ temp files

    public function test_the_temp_file_is_removed_after_success_and_after_every_kind_of_failure(): void
    {
        $before = $this->tempFiles();

        $this->send()->assertOk();                                            // success
        $this->failWith(new ProcessOutcome(1, '', 'boom'));
        $this->send()->assertOk();                                            // non-zero exit
        $this->failWith(new ProcessOutcome(-1, '', '', true));
        $this->send()->assertOk();                                            // timeout
        $this->failWith(new ProcessOutcome(0, 'garbage'));
        $this->send()->assertOk();                                            // unparsable output

        $this->assertSame(4, $this->runner->count());
        $this->assertSame($before, $this->tempFiles());
        foreach ($this->runner->runs as $run) {
            $this->assertTrue($run['image_exists'], 'the file existed while the process ran');
            $this->assertFalse(file_exists($run['command'][1]), 'and it is gone afterwards');
        }
    }

    public function test_the_temp_file_is_removed_even_when_the_runner_crashes(): void
    {
        $before = $this->tempFiles();
        $this->runner->onRun = fn () => throw new RuntimeException('simulated crash');

        $this->send()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertSame($before, $this->tempFiles());
        $this->assertFalse(file_exists($this->runner->runs[0]['command'][1]));

        // and the concurrency slot was given back: the next request is not "busy"
        $this->runner->onRun = null;
        config(['integration.ocr.max_concurrent' => 1]);
        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'success');
    }

    // ------------------------------------------------------------ concurrency

    public function test_when_every_slot_is_busy_the_answer_is_ocr_busy(): void
    {
        config(['integration.ocr.max_concurrent' => 1]);
        $held = Cache::lock('integration:ocr:slot:1', 60);
        $this->assertTrue($held->get());

        $this->send()->assertOk()
            ->assertJsonPath('data.ocr_status', 'unavailable')
            ->assertJsonPath('data.ocr_text', null)
            ->assertJsonPath('warnings', ['OCR_UNAVAILABLE', 'OCR_BUSY']);

        $this->assertSame(0, $this->runner->count());
        $this->assertSame(['fallback', 'busy'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);

        $held->release();
        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'success');
    }

    public function test_a_second_slot_lets_a_second_job_run(): void
    {
        config(['integration.ocr.max_concurrent' => 2]);
        $held = Cache::lock('integration:ocr:slot:1', 60);
        $held->get();

        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'success');

        $held->release();
    }

    // ------------------------------------------------------------ circuit breaker

    public function test_the_circuit_opens_after_three_failures_and_recovers_after_sixty_seconds(): void
    {
        $this->failWith(new ProcessOutcome(1, '', 'boom'));

        foreach ([1, 2, 3] as $n) {
            $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable');
        }
        $this->assertSame(3, $this->runner->count());

        // open: the engine is skipped
        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable')->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);
        $this->assertSame(3, $this->runner->count(), 'no process while the circuit is open');
        $this->assertSame(['fallback', 'circuit_open'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);

        // after 60 seconds one trial request is allowed; a success closes the circuit
        $this->travel(61)->seconds();
        $this->runner->outcome = new ProcessOutcome(0, FakeProcessRunner::tsv([['Take 1 capsule', 95, 1], ['three times a day', 91, 2]]));
        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'success');
        $this->assertSame(4, $this->runner->count());

        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'success');
        $this->assertSame(5, $this->runner->count());
    }

    public function test_a_low_confidence_reading_does_not_count_as_a_failure(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([['smudge', 20, 1]])));

        foreach (range(1, 5) as $n) {
            $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'low_confidence');
        }

        $this->assertSame(5, $this->runner->count(), 'the circuit stays closed: the engine did its job');
    }

    // ------------------------------------------------------------ stub mode and failure simulation

    public function test_stub_mode_runs_no_process_and_creates_no_files_or_logs(): void
    {
        config(['integration.mode' => 'stub']);
        $before = $this->tempFiles();

        $first = $this->send()->assertOk()
            ->assertJsonPath('data.source', 'stub')
            ->assertJsonPath('data.ocr_status', 'success')
            ->assertJsonPath('warnings', []);
        $second = $this->send();

        $this->assertSame($first->json('data'), $second->json('data'), 'deterministic');
        $this->assertStringContainsString('Amoxicillin', $first->json('data.ocr_text'));
        $this->assertSame(0, $this->runner->count());
        $this->assertSame($before, $this->tempFiles());
        $this->assertSame(0, IntegrationLog::count());
    }

    public function test_the_stub_provider_can_also_be_chosen_with_ocr_provider(): void
    {
        config(['integration.ocr.provider' => 'stub']);

        $this->send()->assertOk()->assertJsonPath('data.source', 'stub');
        $this->assertSame(0, $this->runner->count());
    }

    public function test_stub_mode_still_validates_the_upload(): void
    {
        config(['integration.mode' => 'stub']);

        $this->send($this->upload('plain text', 'x.jpg'))->assertStatus(422);
    }

    public function test_force_fail_makes_ocr_unavailable_without_starting_a_process(): void
    {
        config(['integration.ocr.force_fail' => true]);

        $this->send()->assertOk()->assertJsonPath('data.ocr_status', 'unavailable')->assertJsonPath('warnings', ['OCR_UNAVAILABLE']);

        $this->assertSame(0, $this->runner->count());
        $this->assertSame(['failure', 'forced_failure'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
    }

    public function test_stub_force_fail_and_unknown_providers_are_refused_in_production_unless_allowed(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config(['integration.mode' => 'stub']);
        $this->send()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        config(['integration.mode' => 'live', 'integration.ocr.force_fail' => true]);
        $this->send()->assertStatus(500);

        config(['integration.ocr.force_fail' => false, 'integration.ocr.provider' => 'stub']);
        $this->send()->assertStatus(500);
        $this->assertSame(0, $this->runner->count());

        config(['integration.allow_stub_in_production' => true]);
        $this->send()->assertOk()->assertJsonPath('data.source', 'stub');

        config(['integration.allow_stub_in_production' => false, 'integration.ocr.provider' => 'google']);
        $this->send()->assertStatus(500);
    }

    // ------------------------------------------------------------ privacy

    public function test_no_text_file_name_or_image_content_is_ever_logged_or_stored(): void
    {
        $this->failWith(new ProcessOutcome(0, FakeProcessRunner::tsv([
            ['ZEBRA-SECRET-PRESCRIPTION', 97, 1], ['patient', 96, 1], ['Dela', 95, 2], ['Cruz', 95, 2],
        ])));

        $response = $this->send($this->image(500, 200, 'png', 'patient-juan-delacruz.png'))->assertOk();
        $this->assertStringContainsString('ZEBRA-SECRET-PRESCRIPTION', $response->json('data.ocr_text'), 'the caller does get the text');

        $this->failWith(new ProcessOutcome(1, 'ZEBRA-SECRET-PRESCRIPTION', 'ZEBRA-SECRET-PRESCRIPTION'));
        $this->send($this->image(500, 200, 'png', 'patient-juan-delacruz.png'))->assertOk();

        $stored = json_encode(IntegrationLog::all()->toArray()).json_encode(ApiCache::all()->toArray());
        foreach (['ZEBRA', 'SECRET', 'patient', 'juan', 'delacruz', 'Dela', 'Cruz', '.png', 'PNG', 'tsv', 'pharmassist-ocr'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $stored, "found '{$forbidden}' in stored data");
        }

        $rows = IntegrationLog::where('provider', 'ocr')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('parse_image', $row->operation);
            $this->assertContains($row->error_message, [null, 'nonzero_exit']);
        }
        $this->assertSame(0, ApiCache::count(), 'OCR results are never cached');
    }

    public function test_every_attempt_writes_exactly_one_log_row_with_the_request_id(): void
    {
        $response = $this->send()->assertOk();

        $this->assertSame(1, IntegrationLog::count());
        $log = $this->lastLog();
        $this->assertSame([$response->json('request_id'), 'ocr', 'parse_image', 'success', 200, null],
            [$log->request_id, $log->provider, $log->operation, $log->outcome, $log->status_code, $log->error_message]);
        $this->assertGreaterThanOrEqual(0, $log->latency_ms);
    }

    // ------------------------------------------------------------ text clean-up

    public function test_text_is_trimmed_normalised_cleaned_and_capped(): void
    {
        $service = $this->app->make(OcrService::class);

        $this->assertSame("Take one tablet\n\nfrom the box",$service->normalise("  Take  one\t\ttablet  \r\n\r\n\r\n\r\nfrom\u{00A0}the   box \n"));
        $this->assertSame('Amoxicillin 500', $service->normalise("Amoxi\x00cillin\x07 \x1B500\x7F"), 'control characters are removed');
        $this->assertSame("a\n\nb", $service->normalise("a\n\n\n\n\nb"));
        $this->assertSame('', $service->normalise("   \n\t  "));
        $this->assertSame('Caf', $service->normalise("Caf\xC3"), 'invalid UTF-8 bytes are dropped, not fatal');

        $capped = $service->normalise(str_repeat('abcde ', 2000)); // 12 000 characters
        $this->assertSame(5000, mb_strlen($capped));
    }

    // ------------------------------------------------------------ the real process runner

    public function test_the_real_process_runner_runs_a_program_times_out_and_finds_binaries(): void
    {
        $runner = new SymfonyProcessRunner;

        $ok = $runner->run([PHP_BINARY, '-r', 'echo "hello";'], 10);
        $this->assertSame([0, 'hello', false], [$ok->exitCode, $ok->stdout, $ok->timedOut]);

        $failed = $runner->run([PHP_BINARY, '-r', 'exit(3);'], 10);
        $this->assertSame(3, $failed->exitCode);

        $started = microtime(true);
        $slow = $runner->run([PHP_BINARY, '-r', 'sleep(5);'], 0.5);
        $this->assertTrue($slow->timedOut);
        $this->assertLessThan(3.0, microtime(true) - $started, 'the process is killed at the timeout');

        $this->assertSame(PHP_BINARY, $runner->findExecutable(PHP_BINARY));
        $this->assertNull($runner->findExecutable(__DIR__.'/definitely-not-here.exe'));
        $this->assertNull($runner->findExecutable('no-such-program-pharmassist'));
        $this->assertNull($runner->findExecutable(''));
    }
}
