<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use TinyOwl\Observability\TinyOwl;

final class TinyOwlServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__ . '/../../config/tinyowl.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'tinyowl');

        $this->app->singleton(TinyOwl::class, function (Application $app): TinyOwl {
            $config = $app['config']->get('tinyowl', []);

            return new TinyOwl([
                'apiKey' => (string) ($config['api_key'] ?? ''),
                'projectSecret' => (string) ($config['project_secret'] ?? ''),
                'service' => $config['service'] ?? null,
                'environment' => $config['environment'] ?? null,
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            self::CONFIG_PATH => $this->app->configPath('tinyowl.php'),
        ], 'tinyowl-config');
    }
}
