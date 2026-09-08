<?php

declare(strict_types=1);

/**
 * Framework integration snippets for Laravel and Symfony.
 *
 * These are documentation examples — they are not executed by CI.
 * Copy the relevant block into your application.
 */

use TinyOwl\Observability\TinyOwl;

// Shared client — construct once at startup (guarded so this file is safe to lint).
$apiKey = getenv('TINYOWL_API_KEY') ?: '';
$secret = getenv('TINYOWL_PROJECT_SECRET') ?: '';
if ($apiKey === '' || $secret === '') {
    return;
}

$client = new TinyOwl([
    'apiKey' => $apiKey,
    'projectSecret' => $secret,
    'defaultContext' => ['service' => 'web'],
]);

// ---------------------------------------------------------------------------
// Laravel — register as a singleton, scope per request in middleware
// ---------------------------------------------------------------------------
//
// // app/Providers/AppServiceProvider.php
// $this->app->singleton(TinyOwl::class, function () {
//     return new TinyOwl([
//         'apiKey'        => config('services.tinyowl.key'),
//         'projectSecret' => config('services.tinyowl.secret'),
//         'defaultContext'=> ['service' => config('app.name')],
//     ]);
// });
//
// // app/Http/Middleware/TinyOwlRequestLogger.php
// public function handle(Request $request, Closure $next)
// {
//     $logger = app(TinyOwl::class)->withContext([
//         'requestId' => $request->header('X-Request-Id', ''),
//         'method'    => $request->method(),
//         'path'      => $request->path(),
//     ]);
//     $request->attributes->set('tinyowl', $logger);
//     $logger->info('Request started');
//     $response = $next($request);
//     $logger->info('Request finished', ['status' => $response->getStatusCode()]);
//     return $response;
// }

// ---------------------------------------------------------------------------
// Symfony — register as a service, wrap in an EventSubscriber
// ---------------------------------------------------------------------------
//
// # config/services.yaml
// TinyOwl\Observability\TinyOwl:
//     arguments:
//         $config:
//             apiKey: '%env(TINYOWL_API_KEY)%'
//             projectSecret: '%env(TINYOWL_PROJECT_SECRET)%'
//             defaultContext:
//                 service: '%kernel.environment%'
//
// class TinyOwlRequestSubscriber implements EventSubscriberInterface
// {
//     public function __construct(private TinyOwl $client) {}
//
//     public static function getSubscribedEvents(): array
//     {
//         return [
//             RequestEvent::class  => 'onRequest',
//             ResponseEvent::class => 'onResponse',
//         ];
//     }
//
//     public function onRequest(RequestEvent $event): void
//     {
//         $req = $event->getRequest();
//         $logger = $this->client->withContext([
//             'requestId' => $req->headers->get('X-Request-Id', ''),
//             'method'    => $req->getMethod(),
//             'path'      => $req->getPathInfo(),
//         ]);
//         $req->attributes->set('tinyowl', $logger);
//         $logger->info('Request started');
//     }
//
//     public function onResponse(ResponseEvent $event): void
//     {
//         $logger = $event->getRequest()->attributes->get('tinyowl');
//         if ($logger instanceof TinyOwl) {
//             $logger->info('Request finished', ['status' => $event->getResponse()->getStatusCode()]);
//         }
//     }
// }

$client->info('Framework snippet file loaded (not a live request)');
