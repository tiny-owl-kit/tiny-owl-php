<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Tests;

use PHPUnit\Framework\TestCase;
use TinyOwl\Observability\Exception\TinyOwlValidationException;
use TinyOwl\Observability\TinyOwl;
use TinyOwl\Observability\Transport;

final class ClientTest extends TestCase
{
    private const INGEST_URL = 'https://be.tiny-owl-kit.io/api/ingest';
    private const API_KEY = 'test-api-key';
    private const SECRET = 'test-project-secret';

    /** @var array{url: string, body: string, headers: array<string, string>}|null */
    private ?array $lastRequest = null;

    protected function setUp(): void
    {
        $this->lastRequest = null;
        Transport::$testHandler = function (string $url, string $body, array $headers) {
            $this->lastRequest = ['url' => $url, 'body' => $body, 'headers' => $headers];

            return ['status' => 201, 'body' => '{"success":true}'];
        };
    }

    protected function tearDown(): void
    {
        Transport::$testHandler = null;
    }

    /** @param array<string, mixed> $extra */
    private function makeClient(array $extra = []): TinyOwl
    {
        return new TinyOwl(array_merge([
            'apiKey' => self::API_KEY,
            'projectSecret' => self::SECRET,
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function lastPayload(): array
    {
        $this->assertNotNull($this->lastRequest);

        return json_decode($this->lastRequest['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMissingApiKeyRaises(): void
    {
        $this->expectException(TinyOwlValidationException::class);
        $this->expectExceptionMessage('apiKey');
        new TinyOwl(['apiKey' => '', 'projectSecret' => self::SECRET]);
    }

    public function testNonStringCredentialsRaise(): void
    {
        $this->expectException(TinyOwlValidationException::class);
        $this->expectExceptionMessage('apiKey');
        new TinyOwl(['apiKey' => false, 'projectSecret' => self::SECRET]);
    }

    public function testMissingSecretRaises(): void
    {
        $this->expectException(TinyOwlValidationException::class);
        $this->expectExceptionMessage('projectSecret');
        new TinyOwl(['apiKey' => self::API_KEY, 'projectSecret' => '']);
    }

    public function testAutoTraceIdDefaultOn(): void
    {
        $cfg = $this->makeClient()->getConfig();
        $this->assertTrue($cfg['autoTraceId']);
        $this->assertArrayHasKey('instanceTraceId', $cfg);
        $this->assertSame(36, strlen($cfg['instanceTraceId']));
    }

    public function testAutoTraceIdFalse(): void
    {
        $cfg = $this->makeClient(['autoTraceId' => false])->getConfig();
        $this->assertArrayNotHasKey('instanceTraceId', $cfg);
    }

    public function testGetConfigNeverLeaksSecrets(): void
    {
        $cfg = $this->makeClient()->getConfig();
        $this->assertArrayNotHasKey('apiKey', $cfg);
        $this->assertArrayNotHasKey('projectSecret', $cfg);
        $this->assertTrue($cfg['hasApiKey']);
        $this->assertTrue($cfg['hasProjectSecret']);
    }

    public function testBaseUrlDefault(): void
    {
        $this->assertSame('https://be.tiny-owl-kit.io/api', $this->makeClient()->getConfig()['baseUrl']);
    }

    public function testCustomBaseUrlTrailingSlashStripped(): void
    {
        $client = $this->makeClient(['baseUrl' => 'http://localhost:5001/api/']);
        $this->assertSame('http://localhost:5001/api', $client->getConfig()['baseUrl']);
    }

    public function testEmptyMessageRaises(): void
    {
        $this->expectException(TinyOwlValidationException::class);
        $this->expectExceptionMessage('message');
        $this->makeClient()->log('');
    }

    public function testInvalidSeverityRaises(): void
    {
        $this->expectException(TinyOwlValidationException::class);
        $this->expectExceptionMessage('severity');
        $this->makeClient()->log('msg', 'debug');
    }

    public function testValidSeveritiesAccepted(): void
    {
        foreach (['info', 'warning', 'error'] as $sev) {
            $this->makeClient()->log('msg', $sev);
            $this->assertSame($sev, $this->lastPayload()['severity']);
        }
    }

    public function testDefaultContextMergedAndCallSiteWins(): void
    {
        $client = $this->makeClient(['defaultContext' => ['service' => 'billing', 'env' => 'prod']]);
        $client->info('msg', ['env' => 'staging']);
        $payload = $this->lastPayload();
        $this->assertSame('billing', $payload['context']['service']);
        $this->assertSame('staging', $payload['context']['env']);
    }

    public function testEmptyContextSentAsEmptyObject(): void
    {
        $this->makeClient()->info('msg');
        $this->assertNotNull($this->lastRequest);
        $this->assertStringContainsString('"context":{}', $this->lastRequest['body']);
        $this->assertStringNotContainsString('"context":[]', $this->lastRequest['body']);
    }

    public function testInstanceTraceIdSentTopLevel(): void
    {
        $client = $this->makeClient(['autoTraceId' => true]);
        $expected = $client->getConfig()['instanceTraceId'];
        $client->info('msg');
        $payload = $this->lastPayload();
        $this->assertSame($expected, $payload['traceId']);
        $this->assertArrayNotHasKey('traceId', $payload['context']);
    }

    public function testNoTraceIdWhenAutoOff(): void
    {
        $this->makeClient(['autoTraceId' => false])->info('msg');
        $this->assertArrayNotHasKey('traceId', $this->lastPayload());
    }

    public function testInvalidTraceIdInContextIgnored(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $this->makeClient(['autoTraceId' => false])->info('msg', ['traceId' => 'bad trace!!']);
        } finally {
            restore_error_handler();
        }
        $payload = $this->lastPayload();
        $this->assertArrayNotHasKey('traceId', $payload);
        $this->assertArrayNotHasKey('traceId', $payload['context']);
    }

    public function testValidTraceIdInContextPromoted(): void
    {
        $this->makeClient(['autoTraceId' => false])->info('msg', ['traceId' => 'req-abc-1']);
        $payload = $this->lastPayload();
        $this->assertSame('req-abc-1', $payload['traceId']);
        $this->assertArrayNotHasKey('traceId', $payload['context']);
    }

    public function testWithContextReturnsNewInstance(): void
    {
        $client = $this->makeClient();
        $this->assertNotSame($client, $client->withContext(['x' => 1]));
    }

    public function testParentUnchangedAfterWithContext(): void
    {
        $client = $this->makeClient(['defaultContext' => ['k' => 'v']]);
        $client->withContext(['extra' => 'yes']);
        $client->info('msg');
        $this->assertArrayNotHasKey('extra', $this->lastPayload()['context']);
    }

    public function testChildGetsFreshTraceId(): void
    {
        $client = $this->makeClient(['autoTraceId' => true]);
        $child = $client->withContext(['x' => 1]);
        $this->assertNotSame(
            $client->getConfig()['instanceTraceId'],
            $child->getConfig()['instanceTraceId'],
        );
    }

    public function testChildMergesContext(): void
    {
        $child = $this->makeClient(['defaultContext' => ['service' => 'billing']])
            ->withContext(['request_id' => 'r-1']);
        $child->info('msg');
        $ctx = $this->lastPayload()['context'];
        $this->assertSame('billing', $ctx['service']);
        $this->assertSame('r-1', $ctx['request_id']);
    }

    public function testExplicitTraceIdInWithContext(): void
    {
        $child = $this->makeClient()->withContext(['traceId' => 'custom-trace-1']);
        $this->assertSame('custom-trace-1', $child->getConfig()['instanceTraceId']);
        $child->info('msg');
        $this->assertSame('custom-trace-1', $this->lastPayload()['traceId']);
    }

    public function testInvalidTraceIdInWithContextGeneratesFresh(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $child = $this->makeClient(['autoTraceId' => true])->withContext(['traceId' => 'bad trace!!']);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(36, strlen($child->getConfig()['instanceTraceId']));
    }

    public function testAutoTraceIdFalseNoInstanceTrace(): void
    {
        $child = $this->makeClient(['autoTraceId' => false])->withContext(['x' => 1]);
        $this->assertArrayNotHasKey('instanceTraceId', $child->getConfig());
    }

    public function testApiKeyInBody(): void
    {
        $this->makeClient()->info('msg');
        $this->assertSame(self::API_KEY, $this->lastPayload()['apiKey']);
    }

    public function testSeverityDefaultsToInfo(): void
    {
        $this->makeClient()->log('msg');
        $this->assertSame('info', $this->lastPayload()['severity']);
    }

    public function testSecurityHeadersPresent(): void
    {
        $this->makeClient()->info('msg');
        $headers = $this->lastRequest['headers'];
        $this->assertArrayHasKey('x-signature', $headers);
        $this->assertArrayHasKey('x-timestamp', $headers);
        $this->assertArrayHasKey('x-nonce', $headers);
        $this->assertSame(32, strlen($headers['x-nonce']));
        $this->assertSame(64, strlen($headers['x-signature']));
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame(self::INGEST_URL, $this->lastRequest['url']);
    }
}
