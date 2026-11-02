<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use TinyOwl\Observability\Integration\Psr15Middleware;
use TinyOwl\Observability\TinyOwl;
use TinyOwl\Observability\Transport;

final class Psr15MiddlewareTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->events = [];
        Transport::$testHandler = function (string $url, string $body): array {
            $this->events[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return ['status' => 201, 'body' => '{"success":true}'];
        };
    }

    protected function tearDown(): void
    {
        Transport::$testHandler = null;
    }

    private function client(): TinyOwl
    {
        return new TinyOwl(['apiKey' => 'test-api-key', 'projectSecret' => 'test-project-secret']);
    }

    /** @param callable(ServerRequestInterface): ResponseInterface $respond */
    private function handler(callable $respond): RequestHandlerInterface
    {
        return new class ($respond) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $respond */
            public function __construct(private $respond)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->respond)($request);
            }
        };
    }

    public function testSuccessfulResponseIsNotReported(): void
    {
        $middleware = new Psr15Middleware($this->client());

        $response = $middleware->process(
            new ServerRequest('GET', 'https://example.test/health'),
            $this->handler(static fn () => new Response(200)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->events);
    }

    public function testClientErrorReportsRouteTemplateWithoutQueryOrSensitiveData(): void
    {
        $middleware = new Psr15Middleware($this->client(), static fn () => '/users/{id}');
        $request = new ServerRequest(
            'GET',
            'https://example.test/users/42?token=query-secret',
            ['Cookie' => 'sid=cookie-secret', 'Authorization' => 'Bearer header-secret'],
        );

        $middleware->process($request, $this->handler(static fn () => new Response(404)));

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertSame('warning', $event['severity']);
        self::assertSame('HTTP 404', $event['message']);
        self::assertSame('GET', $event['context']['method']);
        self::assertSame('/users/{id}', $event['context']['endpoint']);
        self::assertSame('/users/{id}', $event['context']['routeTemplate']);
        self::assertSame(404, $event['context']['statusCode']);
        self::assertArrayHasKey('durationMs', $event['context']);

        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('query-secret', $encoded);
        self::assertStringNotContainsString('cookie-secret', $encoded);
        self::assertStringNotContainsString('header-secret', $encoded);
    }

    public function testEndpointFallsBackToPathWithoutQueryAndOmitsRouteTemplate(): void
    {
        $middleware = new Psr15Middleware($this->client());

        $middleware->process(
            new ServerRequest('GET', 'https://example.test/users/42?token=query-secret'),
            $this->handler(static fn () => new Response(404)),
        );

        self::assertCount(1, $this->events);
        self::assertSame('/users/42', $this->events[0]['context']['endpoint']);
        self::assertArrayNotHasKey('routeTemplate', $this->events[0]['context']);
    }

    public function testUncaughtExceptionIsReportedAsErrorAndRethrown(): void
    {
        $middleware = new Psr15Middleware($this->client());

        try {
            $middleware->process(
                new ServerRequest('POST', 'https://example.test/orders'),
                $this->handler(static fn () => throw new RuntimeException('boom')),
            );
            self::fail('Exception was swallowed by the middleware');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertCount(1, $this->events);
        self::assertSame('error', $this->events[0]['severity']);
        self::assertSame('Unhandled exception: RuntimeException', $this->events[0]['message']);
        self::assertSame(500, $this->events[0]['context']['statusCode']);
        self::assertSame('POST', $this->events[0]['context']['method']);
    }

    public function testSdkFailureDoesNotBreakTheResponse(): void
    {
        Transport::$testHandler = static fn () => throw new RuntimeException('network down');
        $middleware = new Psr15Middleware($this->client());

        $response = $middleware->process(
            new ServerRequest('GET', 'https://example.test/orders'),
            $this->handler(static fn () => new Response(500)),
        );

        self::assertSame(500, $response->getStatusCode());
    }

    public function testNonStringRouteTemplateIsIgnored(): void
    {
        $middleware = new Psr15Middleware($this->client(), static fn () => 42);

        $middleware->process(
            new ServerRequest('GET', 'https://example.test/orders/7'),
            $this->handler(static fn () => new Response(404)),
        );

        self::assertCount(1, $this->events);
        self::assertSame('/orders/7', $this->events[0]['context']['endpoint']);
        self::assertArrayNotHasKey('routeTemplate', $this->events[0]['context']);
    }
}
