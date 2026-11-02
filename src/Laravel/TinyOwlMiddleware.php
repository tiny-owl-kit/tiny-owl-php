<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Laravel;

use Closure;
use Throwable;
use TinyOwl\Observability\Integration\RequestReporter;
use TinyOwl\Observability\TinyOwl;

/**
 * Laravel middleware that reports uncaught exceptions and responses with status >= 400.
 *
 * The start time and any caught exception are stored in the request attributes.
 * The event is sent from terminate(), after the response has been sent to the client.
 * The route is read in terminate() because Laravel matches routes after global middleware has started.
 */
final class TinyOwlMiddleware
{
    private const STARTED_AT_KEY = 'tinyowl.started_at';
    private const ERROR_CLASS_KEY = 'tinyowl.error_class';

    /**
     * @param Closure(mixed): mixed $next
     */
    public function handle(mixed $request, Closure $next): mixed
    {
        $request->attributes->set(self::STARTED_AT_KEY, hrtime(true));

        try {
            return $next($request);
        } catch (Throwable $e) {
            $request->attributes->set(self::ERROR_CLASS_KEY, $e::class);

            throw $e;
        }
    }

    public function terminate(mixed $request, mixed $response): void
    {
        try {
            $this->report($request, $response);
        } catch (Throwable $e) {
            error_log('TinyOwl SDK: failed to report request: ' . $e->getMessage());
        }
    }

    private function report(mixed $request, mixed $response): void
    {
        $statusCode = (int) $response->getStatusCode();
        $startedAt = $request->attributes->get(self::STARTED_AT_KEY, hrtime(true));
        $exception = $response->exception ?? null;
        $errorClass = $exception instanceof Throwable
            ? $exception::class
            : $request->attributes->get(self::ERROR_CLASS_KEY);

        $route = $request->route();
        $template = $route?->uri();
        $routeTemplate = is_string($template) && $template !== '' ? '/' . ltrim($template, '/') : null;
        $endpoint = $routeTemplate ?? '/' . ltrim((string) $request->path(), '/');

        RequestReporter::report(
            app(TinyOwl::class),
            (string) $request->method(),
            $endpoint,
            $routeTemplate,
            $statusCode,
            (hrtime(true) - (int) $startedAt) / 1_000_000,
            is_string($errorClass) && $statusCode >= 500 ? $errorClass : null,
        );
    }
}
