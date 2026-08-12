<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestContextTest extends TestCase
{
    public function test_api_response_contains_generated_request_id(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk()->assertHeader('X-Request-ID');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $response->headers->get('X-Request-ID'),
        );
    }

    public function test_api_accepts_only_a_safe_caller_request_id(): void
    {
        $safe = $this->withHeader('X-Request-ID', 'gateway-01.request_42')->getJson('/api/ping');
        $unsafe = $this->withHeader('X-Request-ID', "bad\nvalue")->getJson('/api/ping');

        $safe->assertHeader('X-Request-ID', 'gateway-01.request_42');
        $this->assertNotSame("bad\nvalue", $unsafe->headers->get('X-Request-ID'));
    }
}
