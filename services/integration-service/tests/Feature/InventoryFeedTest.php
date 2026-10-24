<?php

namespace Tests\Feature;

use App\Models\ApiCache;
use App\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The translator reads untrusted XML: most of these tests are attacks that must be refused. */
class InventoryFeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'integration-test-key';
    private const URL = '/internal/v1/inventory-feed/translate';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'integration-service',
            'integration.feed.max_kb' => 512,
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function sample(string $name): string
    {
        return (string) file_get_contents(base_path('../../docs/samples/'.$name));
    }

    /** Posts a raw body with an exact Content-Type (an empty type sends none). */
    private function feed(string $body, string $type = 'application/xml', bool $withKey = true)
    {
        $headers = ['Accept' => 'application/json'] + ($withKey ? ['X-Internal-Key' => self::KEY] : []) + ($type === '' ? [] : ['Content-Type' => $type]);

        return $this->call('POST', self::URL, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function item(array $overrides = []): string
    {
        $v = $overrides + [
            'genericName' => 'Amoxicillin', 'brandName' => '', 'strength' => '500mg', 'dosageForm' => 'Capsule',
            'quantity' => '60', 'price' => '10.50', 'batchNo' => 'B-1', 'expirationDate' => '2028-06-30',
        ];

        $xml = '';
        foreach ($v as $tag => $value) {
            if ($value !== null) {
                $xml .= "<{$tag}>".htmlspecialchars((string) $value, ENT_XML1)."</{$tag}>";
            }
        }

        return "<item>{$xml}</item>";
    }

    private function xml(string $items, string $attributes = 'branchId="1" generatedAt="2026-10-25T08:00:00Z"', string $prolog = '<?xml version="1.0" encoding="UTF-8"?>'): string
    {
        return "{$prolog}<inventoryFeed {$attributes}>{$items}</inventoryFeed>";
    }

    private function lastLog(): IntegrationLog
    {
        return IntegrationLog::where('provider', 'xml_feed')->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------ authentication

    public function test_a_missing_or_wrong_internal_key_returns_401(): void
    {
        $this->feed($this->sample('inventory-feed.xml'), 'application/xml', false)->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->call('POST', self::URL, [], [], [], $this->transformHeadersToServerVars(['X-Internal-Key' => 'wrong', 'Content-Type' => 'application/xml']), '<a/>')->assertStatus(401);

        $this->assertSame(0, IntegrationLog::count());
    }

    // ------------------------------------------------------------ translating the samples

    public function test_the_valid_xml_sample_is_translated_to_normalised_json(): void
    {
        $response = $this->feed($this->sample('inventory-feed.xml'))->assertOk()
            ->assertJsonStructure(['status', 'service', 'request_id', 'data' => ['branch_id', 'generated_at', 'items'], 'error', 'warnings'])
            ->assertJsonPath('service', 'integration-service')
            ->assertJsonPath('data.branch_id', 1)
            ->assertJsonPath('data.generated_at', '2026-10-25T08:00:00Z')
            ->assertJsonPath('error', null);

        $items = $response->json('data.items');
        $this->assertCount(8, $items);
        $this->assertSame(range(1, 8), array_column($items, 'row'));
        $this->assertSame(
            ['row', 'generic_name', 'brand_name', 'strength', 'dosage_form', 'quantity', 'price', 'batch_no', 'expiration_date', 'min_threshold'],
            array_keys($items[0])
        );
        $this->assertSame(
            ['row' => 1, 'generic_name' => 'Amoxicillin', 'brand_name' => '', 'strength' => '500mg', 'dosage_form' => 'Capsule', 'quantity' => 60, 'price' => '10.50', 'batch_no' => 'B27-AMOX-01', 'expiration_date' => '2028-06-30', 'min_threshold' => null],
            $items[0]
        );
        $this->assertSame(15, $items[1]['min_threshold']);
        $this->assertSame('Neozep', $items[1]['brand_name']);
        $this->assertSame('IBUPROFEN', $items[3]['generic_name'], 'values are passed on as written; matching ignores case later');
    }

    public function test_the_same_data_as_json_gives_exactly_the_same_result(): void
    {
        $fromXml = $this->feed($this->sample('inventory-feed.xml'), 'application/xml')->assertOk()->json('data');
        $fromJson = $this->feed($this->sample('inventory-feed.json'), 'application/json')->assertOk()->json('data');

        $this->assertSame($fromXml, $fromJson);
        $this->assertCount(8, $fromJson['items']);
    }

    public function test_text_xml_and_a_charset_parameter_are_accepted(): void
    {
        $this->feed($this->sample('inventory-feed.xml'), 'text/xml')->assertOk();
        $this->feed($this->sample('inventory-feed.xml'), 'application/xml; charset=utf-8')->assertOk();
        $this->feed($this->sample('inventory-feed.xml'), 'TEXT/XML;charset=UTF-8')->assertOk();
        $this->feed("\xEF\xBB\xBF".$this->sample('inventory-feed.xml'))->assertOk(); // a UTF-8 byte order mark
    }

    public function test_the_bad_row_sample_is_translated_as_written_so_inventory_can_reject_the_rows(): void
    {
        $items = $this->feed($this->sample('inventory-feed-bad.xml'))->assertOk()->json('data.items');

        $this->assertCount(8, $items);
        $this->assertSame(-5, $items[2]['quantity'], 'a negative quantity is not judged here');
        $this->assertSame('0', $items[4]['price']);
        $this->assertSame('2020-01-31', $items[5]['expiration_date']);
        $this->assertSame('Unobtainium', $items[1]['generic_name']);
    }

    public function test_empty_brand_special_characters_unicode_and_optional_elements(): void
    {
        $body = $this->xml(
            $this->item(['genericName' => 'Vitamin B & C <forte> "plus"', 'brandName' => 'Biogesic®', 'strength' => '10mg/2mg', 'minThreshold' => '7'])
            .$this->item(['genericName' => 'Paracetamol', 'brandName' => null]) // brandName element missing entirely is a schema error: see below
        );
        // brandName is required (it may be empty): the second item above must be rejected
        $this->feed($body)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML');

        $ok = $this->feed($this->xml($this->item(['genericName' => 'Vitamin B & C <forte> "plus"', 'brandName' => 'Biogesic®', 'strength' => '10mg/2mg', 'minThreshold' => '7'])))->assertOk();
        $this->assertSame(['Vitamin B & C <forte> "plus"', 'Biogesic®', 7], [
            $ok->json('data.items.0.generic_name'), $ok->json('data.items.0.brand_name'), $ok->json('data.items.0.min_threshold'),
        ]);
    }

    public function test_quantity_and_price_are_passed_on_unjudged_and_a_time_zone_on_the_date_is_dropped(): void
    {
        $body = $this->xml(
            $this->item(['quantity' => 'abc', 'price' => '1.999', 'expirationDate' => '2028-01-31Z'])
            .$this->item(['quantity' => ' 12 ', 'price' => ' 3.50 ', 'expirationDate' => '2028-02-29+08:00'])
        );

        $items = $this->feed($body)->assertOk()->json('data.items');

        $this->assertSame(['abc', '1.999', '2028-01-31'], [$items[0]['quantity'], $items[0]['price'], $items[0]['expiration_date']]);
        $this->assertSame([12, '3.50', '2028-02-29'], [$items[1]['quantity'], $items[1]['price'], $items[1]['expiration_date']]);
    }

    public function test_up_to_500_items_are_accepted_and_501_are_refused(): void
    {
        $this->feed($this->xml(str_repeat($this->item(), 500)))->assertOk()->assertJsonCount(500, 'data.items');

        $response = $this->feed($this->xml(str_repeat($this->item(), 501)))->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML');
        $this->assertStringContainsString('at most 500 items', $response->json('error.details.errors.0'));
        $this->assertSame('too_many_items', $this->lastLog()->error_message);
    }

    // ------------------------------------------------------------ attacks on the XML parser

    public function test_an_external_entity_attack_returns_400_and_reads_no_file(): void
    {
        $secretFile = tempnam(sys_get_temp_dir(), 'xxe');
        file_put_contents($secretFile, 'TOP-SECRET-FILE-CONTENT-12345');
        $uri = 'file:///'.ltrim(str_replace('\\', '/', $secretFile), '/');

        $body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE inventoryFeed [ <!ENTITY xxe SYSTEM "{$uri}"> ]>
<inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">
  <item><genericName>&xxe;</genericName><brandName/><strength>500mg</strength><dosageForm>Capsule</dosageForm>
        <quantity>1</quantity><price>1.00</price><batchNo>B</batchNo><expirationDate>2028-06-30</expirationDate></item>
</inventoryFeed>
XML;

        try {
            $response = $this->feed($body)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML')->assertJsonPath('data', null);

            $this->assertStringNotContainsString('TOP-SECRET', $response->getContent());
            $this->assertStringNotContainsString($secretFile, $response->getContent());
            $this->assertStringContainsString('DOCTYPE and ENTITY declarations are not allowed', $response->json('error.details.errors.0'));
            $this->assertSame('doctype_forbidden', $this->lastLog()->error_message);
            $this->assertStringNotContainsString('TOP-SECRET', json_encode(IntegrationLog::all()->toArray()));
        } finally {
            @unlink($secretFile);
        }
    }

    public function test_other_doctype_and_entity_tricks_are_refused_too(): void
    {
        $item = $this->item();
        $variants = [
            'external DTD over the network' => '<?xml version="1.0"?><!DOCTYPE inventoryFeed SYSTEM "http://127.0.0.1:9/evil.dtd"><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$item.'</inventoryFeed>',
            'parameter entity' => '<?xml version="1.0"?><!DOCTYPE inventoryFeed [<!ENTITY % p SYSTEM "http://127.0.0.1:9/x">%p;]><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$item.'</inventoryFeed>',
            'lower case doctype' => '<?xml version="1.0"?><!doctype inventoryFeed []><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$item.'</inventoryFeed>',
            'internal entity only' => '<?xml version="1.0"?><!DOCTYPE inventoryFeed [<!ENTITY a "b">]><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$item.'</inventoryFeed>',
            'entity declaration without doctype keyword spacing' => '<?xml version="1.0"?><!  DOCTYPE inventoryFeed><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$item.'</inventoryFeed>',
        ];

        foreach ($variants as $name => $body) {
            $this->feed($body)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML');
        }
    }

    public function test_an_entity_expansion_bomb_is_rejected_quickly(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE inventoryFeed [<!ENTITY a0 "lolololol">';
        for ($i = 1; $i <= 9; $i++) {
            $bomb .= "<!ENTITY a{$i} \"".str_repeat('&a'.($i - 1).';', 10).'">';
        }
        $bomb .= ']><inventoryFeed branchId="1" generatedAt="2026-10-25T08:00:00Z"><item><genericName>&a9;</genericName><brandName/><strength>1</strength><dosageForm>x</dosageForm><quantity>1</quantity><price>1</price><batchNo/><expirationDate>2028-01-01</expirationDate></item></inventoryFeed>';

        $before = memory_get_peak_usage(true);
        $started = microtime(true);
        $response = $this->feed($bomb)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML');

        $this->assertLessThan(2.0, microtime(true) - $started, 'rejected before anything is expanded');
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        $this->assertLessThan(5000, strlen($response->getContent()));
    }

    public function test_a_hidden_encoding_cannot_smuggle_a_doctype_past_the_check(): void
    {
        $utf16 = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE a [<!ENTITY x "y">]><a/>', 'UTF-16LE', 'UTF-8');
        $this->feed("\xFF\xFE".$utf16)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML');

        $this->feed('<?xml version="1.0" encoding="ISO-8859-1"?>'.substr($this->xml($this->item()), 38))->assertStatus(400)
            ->assertJsonPath('error.details.errors.0', 'The XML declaration must say encoding="UTF-8".');

        $this->feed($this->xml($this->item(['genericName' => "Caf\xE9"])))->assertStatus(400); // invalid UTF-8 bytes
    }

    public function test_the_parser_does_not_leave_libxml_error_handling_switched_on(): void
    {
        libxml_use_internal_errors(false);

        $this->feed($this->sample('inventory-feed.xml'))->assertOk();
        $this->feed('<broken')->assertStatus(400);

        $this->assertFalse(libxml_use_internal_errors(), 'the previous setting is restored');
        $this->assertSame([], libxml_get_errors());
    }

    // ------------------------------------------------------------ size and content type

    public function test_an_oversized_body_returns_413(): void
    {
        config(['integration.feed.max_kb' => 1]);

        $this->feed($this->xml(str_repeat($this->item(), 20)))->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
        $this->assertSame([413, 'too_large'], [$this->lastLog()->status_code, $this->lastLog()->error_message]);

        config(['integration.feed.max_kb' => 512]);
        $this->feed($this->xml(str_repeat($this->item(), 20)))->assertOk();

        $this->feed(str_repeat('a', 512 * 1024 + 1))->assertStatus(413); // the default limit is 512 KB
        $this->feed(json_encode(['pad' => str_repeat('a', 512 * 1024)]), 'application/json')->assertStatus(413);
    }

    public function test_any_other_content_type_returns_415(): void
    {
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'text/html', 'application/octet-stream', ''] as $type) {
            $this->feed($this->sample('inventory-feed.xml'), $type)->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        }

        $this->assertSame([415, 'unsupported_media_type'], [$this->lastLog()->status_code, $this->lastLog()->error_message]);
    }

    // ------------------------------------------------------------ schema and well-formedness

    public function test_schema_invalid_xml_returns_400_with_short_messages_and_line_numbers(): void
    {
        $cases = [
            'a required element is missing' => $this->xml($this->item(['genericName' => null])),
            'an unknown element' => $this->xml($this->item(['colour' => 'red'])),
            'brandName element missing' => $this->xml($this->item(['brandName' => null])),
            'the date is not a date' => $this->xml($this->item(['expirationDate' => '31/12/2028'])),
            'the date does not exist' => $this->xml($this->item(['expirationDate' => '2028-02-31'])),
            'minThreshold is negative' => $this->xml($this->item(['minThreshold' => '-1'])),
            'minThreshold is text' => $this->xml($this->item(['minThreshold' => 'many'])),
            'quantity is empty' => $this->xml($this->item(['quantity' => ''])),
            'a name is too long' => $this->xml($this->item(['genericName' => str_repeat('x', 151)])),
            'branchId is zero' => $this->xml($this->item(), 'branchId="0" generatedAt="2026-10-25T08:00:00Z"'),
            'branchId is text' => $this->xml($this->item(), 'branchId="one" generatedAt="2026-10-25T08:00:00Z"'),
            'branchId is missing' => $this->xml($this->item(), 'generatedAt="2026-10-25T08:00:00Z"'),
            'generatedAt is not a date time' => $this->xml($this->item(), 'branchId="1" generatedAt="yesterday"'),
            'no items at all' => $this->xml(''),
            'the wrong root element' => '<?xml version="1.0"?><feed branchId="1" generatedAt="2026-10-25T08:00:00Z">'.$this->item().'</feed>',
            'not even XML' => 'this is just text',
            'not well-formed' => $this->xml($this->item()).'<extra>',
            'empty body' => '',
        ];

        foreach ($cases as $name => $body) {
            $response = $this->feed($body)->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_XML')->assertJsonPath('data', null);

            $errors = $response->json('error.details.errors');
            $this->assertNotEmpty($errors, $name);
            $this->assertLessThanOrEqual(10, count($errors), $name);
            foreach ($errors as $message) {
                $this->assertMatchesRegularExpression('/^Line \d+: /', $message, $name);
                $this->assertLessThanOrEqual(215, mb_strlen($message), $name);
                $this->assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]|/home/|/var/|/app/|\.xsd|\.php|resources[\\\\/]#i', $message, "{$name}: a server path leaked");
            }
        }
    }

    public function test_at_most_ten_messages_are_returned(): void
    {
        $response = $this->feed($this->xml(str_repeat($this->item(['expirationDate' => 'not-a-date']), 40)))->assertStatus(400);

        $this->assertCount(10, $response->json('error.details.errors'));
    }

    // ------------------------------------------------------------ JSON input

    public function test_invalid_json_returns_400_malformed_json(): void
    {
        $good = json_decode($this->sample('inventory-feed.json'), true);
        $with = fn (callable $change) => json_encode($change($good));
        $cases = [
            'not JSON' => '{broken',
            'a JSON string' => '"hello"',
            'no items' => $with(fn ($f) => ['items' => []] + $f),
            'items is not a list' => $with(fn ($f) => ['items' => 'x'] + $f),
            'branch_id missing' => $with(function ($f) { unset($f['branch_id']); return $f; }),
            'branch_id zero' => $with(fn ($f) => ['branch_id' => 0] + $f),
            'generated_at missing' => $with(function ($f) { unset($f['generated_at']); return $f; }),
            'a name missing' => $with(function ($f) { unset($f['items'][0]['generic_name']); return $f; }),
            'brand_name missing' => $with(function ($f) { unset($f['items'][0]['brand_name']); return $f; }),
            'date format' => $with(function ($f) { $f['items'][0]['expiration_date'] = '31/12/2028'; return $f; }),
            'min_threshold negative' => $with(function ($f) { $f['items'][0]['min_threshold'] = -3; return $f; }),
            'quantity is an array' => $with(function ($f) { $f['items'][0]['quantity'] = [1]; return $f; }),
            '501 items' => json_encode(['items' => array_fill(0, 501, $good['items'][0])] + $good),
        ];

        foreach ($cases as $name => $body) {
            $response = $this->feed($body, 'application/json')->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_JSON');
            $this->assertNotEmpty($response->json('error.details.errors'), $name);
            $this->assertLessThanOrEqual(10, count($response->json('error.details.errors')), $name);
        }
    }

    public function test_json_values_are_passed_on_unjudged_like_xml_values(): void
    {
        $feed = json_decode($this->sample('inventory-feed.json'), true);
        $feed['items'][0]['quantity'] = -5;
        $feed['items'][0]['price'] = 0;
        $feed['items'][1]['quantity'] = 'abc';

        $items = $this->feed(json_encode($feed), 'application/json')->assertOk()->json('data.items');

        $this->assertSame([-5, '0', 'abc'], [$items[0]['quantity'], $items[0]['price'], $items[1]['quantity']]);
    }

    // ------------------------------------------------------------ side effects and privacy

    public function test_translating_writes_only_a_log_row_and_makes_no_outbound_call(): void
    {
        Http::fake();
        $cacheRows = ApiCache::count();

        $this->feed($this->sample('inventory-feed.xml'))->assertOk();
        $this->feed('<bad')->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame($cacheRows, ApiCache::count());
        $this->assertSame(2, IntegrationLog::count());
    }

    public function test_every_attempt_writes_one_log_row_with_a_status_and_a_reason_but_never_the_feed(): void
    {
        $ok = $this->feed($this->sample('inventory-feed.xml'))->assertOk();
        $this->feed($this->sample('inventory-feed.json'), 'application/json')->assertOk();
        $this->feed($this->xml($this->item(['genericName' => null])))->assertStatus(400);

        $rows = IntegrationLog::where('provider', 'xml_feed')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['translate_xml', 'success', 200, null], [$rows[0]->operation, $rows[0]->outcome, $rows[0]->status_code, $rows[0]->error_message]);
        $this->assertSame(['translate_json', 'success'], [$rows[1]->operation, $rows[1]->outcome]);
        $this->assertSame(['translate_xml', 'failure', 400, 'schema_invalid'], [$rows[2]->operation, $rows[2]->outcome, $rows[2]->status_code, $rows[2]->error_message]);
        $this->assertSame($ok->json('request_id'), $rows[0]->request_id);

        $stored = json_encode(IntegrationLog::all()->toArray());
        foreach (['Amoxicillin', 'Neozep', 'B27-', '500mg', '2028', 'genericName'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, "found '{$forbidden}' in stored data");
        }
    }

    // ------------------------------------------------------------ the contract files

    public function test_the_xsd_in_docs_is_identical_to_the_one_the_service_uses(): void
    {
        $this->assertFileExists(base_path('../../docs/schemas/inventory-feed.xsd'));
        $this->assertSame(
            (string) file_get_contents(config('integration.feed.xsd_path')),
            (string) file_get_contents(base_path('../../docs/schemas/inventory-feed.xsd')),
            'docs/schemas/inventory-feed.xsd and resources/schemas/inventory-feed.xsd must stay identical'
        );
    }

    public function test_the_provider_enum_accepts_xml_feed_after_the_migration(): void
    {
        IntegrationLog::create(['provider' => 'xml_feed', 'operation' => 'translate_xml', 'outcome' => 'success', 'latency_ms' => 1]);
        IntegrationLog::create(['provider' => 'osrm', 'operation' => 'table', 'outcome' => 'success', 'latency_ms' => 1]);

        $this->assertSame(2, IntegrationLog::count());
    }
}
