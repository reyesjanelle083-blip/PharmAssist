<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    private const URL = '/internal/v1/health';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => 'test-internal-key',
            'pharmassist.service' => 'identity-service',
        ]);
    }

    public function test_health_requires_the_internal_key(): void
    {
        $this->getJson(self::URL)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY')
            ->assertJsonPath('data', null);

        $this->getJson(self::URL, ['X-Internal-Key' => 'wrong-key'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
    }

    public function test_health_rejects_everything_when_no_key_is_configured(): void
    {
        config(['pharmassist.internal_key' => null]);

        $this->getJson(self::URL, ['X-Internal-Key' => ''])->assertStatus(401);
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_health_returns_the_envelope_with_a_valid_key(): void
    {
        $response = $this->getJson(self::URL, ['X-Internal-Key' => 'test-internal-key']);

        $response->assertOk()
            ->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings'])
            ->assertJsonPath('status', 200)
            ->assertJsonPath('service', 'identity-service')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('error', null)
            ->assertJsonPath('warnings', []);

        $this->assertNotEmpty($response->json('request_id'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_a_sent_request_id_is_echoed_back(): void
    {
        $response = $this->getJson(self::URL, [
            'X-Internal-Key' => 'test-internal-key',
            'X-Request-Id' => 'req-12345678',
        ]);

        $response->assertOk()->assertJsonPath('request_id', 'req-12345678');
        $this->assertSame('req-12345678', $response->headers->get('X-Request-Id'));
    }

    public function test_unknown_routes_render_a_not_found_envelope(): void
    {
        $this->getJson('/nope')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings']);
    }
}
