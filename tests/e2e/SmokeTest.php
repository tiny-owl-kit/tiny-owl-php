<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Tests\E2E;

use PHPUnit\Framework\TestCase;
use TinyOwl\Observability\TinyOwl;

/**
 * Gated E2E smoke test against a live TinyOwl backend.
 *
 * Requires TINYOWL_API_KEY and TINYOWL_PROJECT_SECRET in the environment.
 * Optional TINYOWL_BASE_URL (defaults to production).
 */
final class SmokeTest extends TestCase
{
    public function testIngestReturnsVerifiedEvent(): void
    {
        $apiKey = getenv('TINYOWL_API_KEY') ?: '';
        $secret = getenv('TINYOWL_PROJECT_SECRET') ?: '';
        if ($apiKey === '' || $secret === '') {
            $this->markTestSkipped('TINYOWL_API_KEY and TINYOWL_PROJECT_SECRET are required for E2E');
        }

        $config = [
            'apiKey' => $apiKey,
            'projectSecret' => $secret,
            'defaultContext' => ['service' => 'php-sdk-e2e'],
        ];
        $baseUrl = getenv('TINYOWL_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $config['baseUrl'] = $baseUrl;
        }

        $client = new TinyOwl($config);
        $result = $client->info('PHP SDK e2e smoke', ['sdk' => 'php']);

        $this->assertTrue($result['success'] ?? false, 'expected success=true, got: ' . json_encode($result));
        $this->assertTrue(
            $result['data']['hmacVerified'] ?? false,
            'expected hmacVerified=true, got: ' . json_encode($result),
        );
    }
}
