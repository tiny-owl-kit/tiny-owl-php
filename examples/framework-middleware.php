<?php

declare(strict_types=1);

/**
 * Framework middleware examples (PSR-15 and Laravel).
 *
 * These are documentation snippets and are not executed by CI.
 * The middleware sends an event only for uncaught exceptions and responses
 * with status >= 400. Request bodies, query strings, headers and cookies are never sent.
 */

use TinyOwl\Observability\Integration\Psr15Middleware;
use TinyOwl\Observability\Laravel\TinyOwlMiddleware;
use TinyOwl\Observability\TinyOwl;

$client = new TinyOwl([
    'apiKey' => getenv('TINYOWL_API_KEY') ?: '',
    'projectSecret' => getenv('TINYOWL_PROJECT_SECRET') ?: '',
    'service' => 'checkout-api',
    'environment' => 'production',
]);

// ---------------------------------------------------------------------------
// PSR-15 (Slim, Mezzio, or any PSR-15 pipeline)
// ---------------------------------------------------------------------------
//
// The route resolver runs before the handler, so place the middleware after
// the routing middleware. The resolver is optional; without it the endpoint
// is the request path.
//
// $pipeline->pipe(new Psr15Middleware($client, static function ($request): ?string {
//     // Return the route template set by your router, e.g. "/users/{id}".
//     $template = $request->getAttribute('routeTemplate');
//     return is_string($template) ? $template : null;
// }));

// ---------------------------------------------------------------------------
// Laravel
// ---------------------------------------------------------------------------
//
// // bootstrap/app.php
// return Application::configure(basePath: dirname(__DIR__))
//     ->withMiddleware(function (Middleware $middleware): void {
//         $middleware->append(TinyOwlMiddleware::class);
//     })
//     ->create();
//
// // config/services.php is not required: the package ships config/tinyowl.php.
// // Set TINYOWL_API_KEY, TINYOWL_PROJECT_SECRET, TINYOWL_SERVICE, TINYOWL_ENVIRONMENT.
//
// Publish the config (optional): php artisan vendor:publish --tag=tinyowl-config
