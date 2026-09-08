<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Tests;

use PHPUnit\Framework\TestCase;
use TinyOwl\Observability\Exception\TinyOwlAuthException;
use TinyOwl\Observability\Exception\TinyOwlNetworkException;
use TinyOwl\Observability\Exception\TinyOwlServerException;
use TinyOwl\Observability\Exception\TinyOwlTimeoutException;
use TinyOwl\Observability\TinyOwl;
use TinyOwl\Observability\Transport;

final class TransportTest extends TestCase
{
    protected function tearDown(): void
    {
        Transport::$testHandler = null;
    }

    private function makeClient(): TinyOwl
    {
        return new TinyOwl(['apiKey' => 'key', 'projectSecret' => 'secret']);
    }

    public function test401RaisesAuthError(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 401,
            'body' => '{"message":"Invalid signature"}',
        ];
        $this->expectException(TinyOwlAuthException::class);
        $this->expectExceptionMessage('Invalid signature');
        $this->makeClient()->info('msg');
    }

    public function test403RaisesAuthError(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 403,
            'body' => '{"message":"Forbidden"}',
        ];
        try {
            $this->makeClient()->info('msg');
            $this->fail('expected TinyOwlAuthException');
        } catch (TinyOwlAuthException $e) {
            $this->assertSame(403, $e->statusCode);
        }
    }

    public function test500RaisesServerError(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 500,
            'body' => 'Internal Server Error',
        ];
        try {
            $this->makeClient()->info('msg');
            $this->fail('expected TinyOwlServerException');
        } catch (TinyOwlServerException $e) {
            $this->assertSame(500, $e->statusCode);
        }
    }

    public function test201ReturnsJson(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 201,
            'body' => '{"success":true,"data":{"eventId":"evt-1","hmacVerified":true}}',
        ];
        $result = $this->makeClient()->info('msg');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['hmacVerified']);
    }

    public function testConnectionErrorRaisesNetworkError(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 0,
            'body' => '',
            'errno' => CURLE_COULDNT_CONNECT,
            'error' => 'refused',
        ];
        $this->expectException(TinyOwlNetworkException::class);
        $this->makeClient()->info('msg');
    }

    public function testTimeoutRaisesTimeoutError(): void
    {
        Transport::$testHandler = static fn () => [
            'status' => 0,
            'body' => '',
            'errno' => CURLE_OPERATION_TIMEDOUT,
            'error' => 'timeout',
        ];
        $this->expectException(TinyOwlTimeoutException::class);
        $this->makeClient()->info('msg');
    }

    public function testInsecureHttpWarns(): void
    {
        $warned = false;
        set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
            if (str_contains($errstr, 'plain HTTP')) {
                $warned = true;
            }

            return true;
        });
        try {
            new TinyOwl([
                'apiKey' => 'key',
                'projectSecret' => 'secret',
                'baseUrl' => 'http://example.com/api',
            ]);
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($warned);
    }

    public function testLocalhostHttpDoesNotWarn(): void
    {
        $warned = false;
        set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
            if (str_contains($errstr, 'plain HTTP')) {
                $warned = true;
            }

            return true;
        });
        try {
            new TinyOwl([
                'apiKey' => 'key',
                'projectSecret' => 'secret',
                'baseUrl' => 'http://localhost:5001/api',
            ]);
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($warned);
    }
}
