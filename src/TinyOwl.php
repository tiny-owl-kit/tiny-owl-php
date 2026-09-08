<?php

declare(strict_types=1);

namespace TinyOwl\Observability;

use TinyOwl\Observability\Exception\TinyOwlValidationException;

/**
 * TinyOwl observability client.
 *
 * Usage:
 *
 *     $client = new TinyOwl([
 *         'apiKey'        => getenv('TINYOWL_API_KEY'),
 *         'projectSecret' => getenv('TINYOWL_PROJECT_SECRET'),
 *     ]);
 *     $client->info('App started', ['version' => '1.0.0']);
 */
final class TinyOwl
{
    private const DEFAULT_BASE_URL = 'https://be.tiny-owl-kit.io/api';
    private const DEFAULT_TIMEOUT = 5.0;

    private readonly string $apiKey;
    private readonly string $projectSecret;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly bool $autoTraceId;

    /** @var array<string, mixed> */
    private readonly array $defaultContext;

    private readonly ?string $instanceTraceId;

    /**
     * @param array{
     *     apiKey: string,
     *     projectSecret: string,
     *     baseUrl?: string,
     *     timeout?: float|int,
     *     autoTraceId?: bool,
     *     defaultContext?: array<string, mixed>,
     *     _instanceTraceId?: string|null
     * } $config
     */
    public function __construct(array $config)
    {
        $apiKeyRaw = $config['apiKey'] ?? '';
        $projectSecretRaw = $config['projectSecret'] ?? '';
        $apiKey = is_string($apiKeyRaw) ? $apiKeyRaw : '';
        $projectSecret = is_string($projectSecretRaw) ? $projectSecretRaw : '';

        if ($apiKey === '') {
            throw new TinyOwlValidationException('apiKey is required');
        }
        if ($projectSecret === '') {
            throw new TinyOwlValidationException('projectSecret is required');
        }

        $this->apiKey = $apiKey;
        $this->projectSecret = $projectSecret;
        $this->baseUrl = rtrim($config['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->autoTraceId = $config['autoTraceId'] ?? true;
        $this->defaultContext = $config['defaultContext'] ?? [];

        Transport::warnIfInsecure($this->baseUrl);

        if (array_key_exists('_instanceTraceId', $config)) {
            $this->instanceTraceId = $config['_instanceTraceId'];
        } else {
            $this->instanceTraceId = $this->autoTraceId ? Trace::newId() : null;
        }
    }

    /**
     * Log an info severity event.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function info(string $message, array $context = []): array
    {
        return $this->log($message, Severity::Info->value, $context);
    }

    /**
     * Log a warning severity event.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function warning(string $message, array $context = []): array
    {
        return $this->log($message, Severity::Warning->value, $context);
    }

    /**
     * Log an error severity event.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function error(string $message, array $context = []): array
    {
        return $this->log($message, Severity::Error->value, $context);
    }

    /**
     * Send an event to TinyOwl.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function log(string $message, string $severity = 'info', array $context = []): array
    {
        if ($message === '') {
            throw new TinyOwlValidationException('message is required and must be a non-empty string');
        }

        $valid = array_map(static fn (Severity $s) => $s->value, Severity::cases());
        if (!in_array($severity, $valid, true)) {
            throw new TinyOwlValidationException(
                "Invalid severity '{$severity}'. Must be one of: " . implode(', ', $valid),
            );
        }

        $mergedContext = array_merge($this->defaultContext, $context);

        $rawTraceId = $mergedContext['traceId'] ?? null;
        unset($mergedContext['traceId']);

        $resolvedTraceId = null;
        if (is_string($rawTraceId)) {
            if (Trace::isValid($rawTraceId)) {
                $resolvedTraceId = $rawTraceId;
            } else {
                trigger_error(
                    "TinyOwl SDK: Invalid traceId format '" . substr($rawTraceId, 0, 40)
                    . "' — ignored. Must match [A-Za-z0-9._:-]{1,128}.",
                    E_USER_WARNING,
                );
            }
        } elseif ($this->instanceTraceId !== null) {
            $resolvedTraceId = $this->instanceTraceId;
        }

        $payload = [
            'apiKey' => $this->apiKey,
            'message' => $message,
            'severity' => $severity,
            'context' => $mergedContext,
        ];
        if ($resolvedTraceId !== null) {
            $payload['traceId'] = $resolvedTraceId;
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            Security::createSecureHeaders(
                $message,
                $severity,
                $mergedContext,
                $this->projectSecret,
                $resolvedTraceId,
            ),
        );

        return Transport::sendEvent($this->baseUrl, $payload, $headers, $this->timeout);
    }

    /**
     * Return a new child client with merged default context and a fresh traceId.
     *
     * The parent instance is never modified. If $partial contains a `traceId`
     * key it is used as the child's instance trace ID (after validation);
     * otherwise a fresh UUID v4 is generated (when autoTraceId is on).
     *
     * @param array<string, mixed> $partial
     */
    public function withContext(array $partial): self
    {
        $rawTraceId = $partial['traceId'] ?? null;
        $childTraceId = null;

        if (is_string($rawTraceId)) {
            if (Trace::isValid($rawTraceId)) {
                $childTraceId = $rawTraceId;
            } else {
                trigger_error(
                    "TinyOwl SDK: Invalid traceId in withContext '" . substr($rawTraceId, 0, 40)
                    . "' — a fresh traceId will be generated.",
                    E_USER_WARNING,
                );
                $childTraceId = $this->autoTraceId ? Trace::newId() : null;
            }
        } elseif ($this->autoTraceId) {
            $childTraceId = Trace::newId();
        }

        unset($partial['traceId']);
        $mergedDefault = array_merge($this->defaultContext, $partial);

        return new self([
            'apiKey' => $this->apiKey,
            'projectSecret' => $this->projectSecret,
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'autoTraceId' => $this->autoTraceId,
            'defaultContext' => $mergedDefault,
            '_instanceTraceId' => $childTraceId,
        ]);
    }

    /**
     * Return SDK configuration for debugging. Never exposes secrets or API keys.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        // apiKey / projectSecret are validated non-empty in the constructor, so
        // presence flags are always true for a live instance (never leak values).
        $cfg = [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'autoTraceId' => $this->autoTraceId,
            'hasApiKey' => true,
            'hasProjectSecret' => true,
        ];
        if ($this->instanceTraceId !== null) {
            $cfg['instanceTraceId'] = $this->instanceTraceId;
        }

        return $cfg;
    }
}
