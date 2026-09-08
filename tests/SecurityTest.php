<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Tests;

use PHPUnit\Framework\TestCase;
use TinyOwl\Observability\Security;

/**
 * HMAC signing, canonical JSON, and the shared parity vector.
 *
 * These values are fixed. Every SDK in every language MUST reproduce the same
 * expected_signature given the same inputs. Do not change these constants.
 */
final class SecurityTest extends TestCase
{
    private const PARITY_MESSAGE = 'Payment processed';
    private const PARITY_SEVERITY = 'info';
    /** @var array<string, mixed> */
    private const PARITY_CONTEXT = ['order_id' => 'ORD-9', 'amount' => 49.99];
    private const PARITY_TRACE_ID = 'req-abc-123';
    private const PARITY_TIMESTAMP = '2026-08-19T10:20:30.000Z';
    private const PARITY_NONCE = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
    private const PARITY_SECRET = 'super-secret-project-key-for-testing';
    private const PARITY_CANONICAL = '{"message":"Payment processed","severity":"info",'
        . '"context":{"order_id":"ORD-9","amount":49.99},'
        . '"traceId":"req-abc-123",'
        . '"timestamp":"2026-08-19T10:20:30.000Z",'
        . '"nonce":"a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4"}';
    private const PARITY_SIGNATURE = 'cee771d25dcfb623fcefe37860dbac955fa10bf717e1de789cbbf2865a59b3e8';

    protected function tearDown(): void
    {
        Security::$testTimestamp = null;
        Security::$testNonce = null;
    }

    public function testCompactNoWhitespace(): void
    {
        $this->assertSame('{"a":1}', Security::canonicalJson(['a' => 1]));
    }

    public function testInsertionOrderPreserved(): void
    {
        $this->assertSame('{"z":1,"a":2,"m":3}', Security::canonicalJson(['z' => 1, 'a' => 2, 'm' => 3]));
    }

    public function testUnicodeNotEscaped(): void
    {
        $result = Security::canonicalJson(['msg' => 'héllo wörld']);
        $this->assertStringContainsString('héllo wörld', $result);
        $this->assertStringNotContainsString('\\u', $result);
    }

    public function testSlashNotEscaped(): void
    {
        $result = Security::canonicalJson(['url' => 'https://example.com/path']);
        $this->assertStringContainsString('https://example.com/path', $result);
        $this->assertStringNotContainsString('\\/', $result);
    }

    public function testAmpersandAndAngleBracketsNotEscaped(): void
    {
        $result = Security::canonicalJson(['q' => '<script>alert(1)&x=y</script>']);
        $this->assertStringContainsString('<script>', $result);
        $this->assertStringNotContainsString('\\u003c', $result);
        $this->assertStringNotContainsString('\\u0026', $result);
    }

    public function testNestedContext(): void
    {
        $result = Security::canonicalJson(['context' => ['user' => 'alice', 'meta' => ['env' => 'prod']]]);
        $parsed = json_decode($result, true);
        $this->assertSame('prod', $parsed['context']['meta']['env']);
    }

    public function testEmptyContextSerializesAsObject(): void
    {
        $this->assertSame('{"context":{}}', Security::canonicalJson(['context' => []]));
    }

    public function testParityCanonicalString(): void
    {
        $payload = Security::buildSignablePayload(
            self::PARITY_MESSAGE,
            self::PARITY_SEVERITY,
            self::PARITY_CONTEXT,
            self::PARITY_TIMESTAMP,
            self::PARITY_NONCE,
            self::PARITY_TRACE_ID,
        );
        $this->assertSame(self::PARITY_CANONICAL, Security::canonicalJson($payload));
    }

    public function testParityVector(): void
    {
        $payload = Security::buildSignablePayload(
            self::PARITY_MESSAGE,
            self::PARITY_SEVERITY,
            self::PARITY_CONTEXT,
            self::PARITY_TIMESTAMP,
            self::PARITY_NONCE,
            self::PARITY_TRACE_ID,
        );
        $sig = Security::signPayload($payload, self::PARITY_SECRET);
        $this->assertSame(
            self::PARITY_SIGNATURE,
            $sig,
            "HMAC parity failure!\n  got:      {$sig}\n  expected: " . self::PARITY_SIGNATURE
            . "\n  payload:  " . Security::canonicalJson($payload),
        );
    }

    public function testWithoutTraceId(): void
    {
        $payload = Security::buildSignablePayload('msg', 'info', [], self::PARITY_TIMESTAMP, self::PARITY_NONCE);
        $sig = Security::signPayload($payload, self::PARITY_SECRET);
        $this->assertSame(64, strlen($sig));
    }

    public function testReturnsLowercaseHex(): void
    {
        $payload = Security::buildSignablePayload('msg', 'info', [], self::PARITY_TIMESTAMP, self::PARITY_NONCE);
        $sig = Security::signPayload($payload, self::PARITY_SECRET);
        $this->assertSame(strtolower($sig), $sig);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig);
    }

    public function testDifferentSecretGivesDifferentSig(): void
    {
        $payload = Security::buildSignablePayload('msg', 'info', [], self::PARITY_TIMESTAMP, self::PARITY_NONCE);
        $this->assertNotSame(
            Security::signPayload($payload, 'secret-a'),
            Security::signPayload($payload, 'secret-b'),
        );
    }

    public function testKeyOrderWithoutTraceId(): void
    {
        $p = Security::buildSignablePayload('msg', 'info', ['k' => 'v'], 'ts', 'nn');
        $this->assertSame(['message', 'severity', 'context', 'timestamp', 'nonce'], array_keys($p));
    }

    public function testKeyOrderWithTraceId(): void
    {
        $p = Security::buildSignablePayload('msg', 'info', [], 'ts', 'nn', 'trace-1');
        $this->assertSame(['message', 'severity', 'context', 'traceId', 'timestamp', 'nonce'], array_keys($p));
    }

    public function testTraceIdPositionBeforeTimestamp(): void
    {
        $p = Security::buildSignablePayload('m', 'info', [], 'ts', 'nn', 'tr');
        $keys = array_keys($p);
        $this->assertLessThan(array_search('timestamp', $keys, true), array_search('traceId', $keys, true));
    }

    public function testNonceLengthIs32(): void
    {
        $this->assertSame(32, strlen(Security::generateNonce()));
    }

    public function testNonceHexCharsOnly(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Security::generateNonce());
    }

    public function testNonceUnique(): void
    {
        $nonces = [];
        for ($i = 0; $i < 1000; ++$i) {
            $nonces[Security::generateNonce()] = true;
        }
        $this->assertCount(1000, $nonces);
    }

    public function testTimestampFormat(): void
    {
        $ts = Security::jsIsoTimestamp();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $ts);
        $this->assertStringEndsWith('Z', $ts);
        $msPart = explode('.', $ts)[1];
        $this->assertSame(4, strlen($msPart));
        $this->assertStringEndsWith('Z', $msPart);
    }

    public function testCreateSecureHeadersReturnsThreeHeaders(): void
    {
        $h = Security::createSecureHeaders('msg', 'info', [], 'secret');
        $this->assertSame(['x-signature', 'x-timestamp', 'x-nonce'], array_keys($h));
        $this->assertSame(32, strlen($h['x-nonce']));
        $this->assertSame(64, strlen($h['x-signature']));
        $this->assertSame(strtolower($h['x-signature']), $h['x-signature']);
    }

    public function testSignatureDiffersWithAndWithoutTraceId(): void
    {
        Security::$testTimestamp = self::PARITY_TIMESTAMP;
        Security::$testNonce = self::PARITY_NONCE;
        $with = Security::createSecureHeaders('msg', 'info', [], self::PARITY_SECRET, 'tr');
        $without = Security::createSecureHeaders('msg', 'info', [], self::PARITY_SECRET);
        $this->assertNotSame($with['x-signature'], $without['x-signature']);
    }
}
