<?php

declare(strict_types=1);

/**
 * Basic usage example / E2E smoke test.
 *
 * Requires TINYOWL_API_KEY and TINYOWL_PROJECT_SECRET in the environment.
 * Optional TINYOWL_BASE_URL (defaults to https://be.tiny-owl-kit.io/api).
 *
 * Look for hmacVerified: true in the response.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TinyOwl\Observability\TinyOwl;

$apiKey = getenv('TINYOWL_API_KEY') ?: '';
$secret = getenv('TINYOWL_PROJECT_SECRET') ?: '';
if ($apiKey === '' || $secret === '') {
    fwrite(STDERR, "TINYOWL_API_KEY and TINYOWL_PROJECT_SECRET are required\n");
    exit(1);
}

$config = [
    'apiKey' => $apiKey,
    'projectSecret' => $secret,
    'defaultContext' => ['service' => 'my-app', 'env' => 'production'],
];
$baseUrl = getenv('TINYOWL_BASE_URL');
if (is_string($baseUrl) && $baseUrl !== '') {
    $config['baseUrl'] = $baseUrl;
}

$client = new TinyOwl($config);

$client->info('Application started', ['version' => '1.0.0']);
$client->warning('Memory usage high', ['used_pct' => 85]);
$result = $client->error('Unhandled exception', ['code' => 'ERR_UNKNOWN']);

$client->log('Custom event', 'info', ['custom' => true]);

$reqLogger = $client->withContext(['request_id' => 'req-xyz-123', 'user_id' => 'u-456']);
$reqLogger->info('Request received');
$reqLogger->info('Processing started');
$reqLogger->error('Downstream timeout', ['service' => 'payments']);

echo json_encode($client->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$verified = $result['data']['hmacVerified'] ?? false;
if ($verified !== true) {
    fwrite(STDERR, "E2E failed: hmacVerified is not true\n");
    exit(1);
}
