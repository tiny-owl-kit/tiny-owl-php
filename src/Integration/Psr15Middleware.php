<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Integration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use TinyOwl\Observability\TinyOwl;

/**
 * PSR-15 middleware that reports uncaught exceptions and responses with status >= 400.
 *
 * Register it after the routing middleware so that the route template resolver
 * can read the matched route from the request attributes.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    /**
     * @param (callable(ServerRequestInterface): mixed)|null $routeTemplateResolver
     */
    public function __construct(
        private readonly TinyOwl $client,
        private readonly mixed $routeTemplateResolver = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startedAt = hrtime(true);
        $method = $request->getMethod();
        $routeTemplate = $this->resolveRouteTemplate($request);
        $endpoint = $routeTemplate ?? $request->getUri()->getPath();

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            RequestReporter::report(
                $this->client,
                $method,
                $endpoint,
                $routeTemplate,
                500,
                $this->elapsedMs($startedAt),
                $e::class,
            );

            throw $e;
        }

        RequestReporter::report(
            $this->client,
            $method,
            $endpoint,
            $routeTemplate,
            $response->getStatusCode(),
            $this->elapsedMs($startedAt),
        );

        return $response;
    }

    private function resolveRouteTemplate(ServerRequestInterface $request): ?string
    {
        if ($this->routeTemplateResolver === null) {
            return null;
        }

        try {
            $template = ($this->routeTemplateResolver)($request);
        } catch (Throwable) {
            return null;
        }

        return is_string($template) && $template !== '' ? $template : null;
    }

    private function elapsedMs(int|float $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
