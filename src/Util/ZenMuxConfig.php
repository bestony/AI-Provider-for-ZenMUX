<?php

/**
 * ZenMUX configuration reader.
 *
 * Configuration is deliberately environment/constant based. Credentials remain owned by the AI
 * Client registry; this provider never reads the connectors option directly.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Util;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

final class ZenMuxConfig
{
    public const VERSION = '1.0.0';
    public const PROVIDER_ID = 'zenmux';
    public const DEFAULT_BASE_URL = 'https://zenmux.ai/api/v1';
    public const DEFAULT_MODEL = 'openai/gpt-5';
    public const DEFAULT_IMAGE_MODEL = 'openai/gpt-image-2';

    /**
     * Resolve an environment variable or PHP constant.
     *
     * @param string $name Configuration name.
     * @return string Resolved scalar value, or an empty string.
     */
    public static function env(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (defined($name)) {
            $constant = constant($name);
            if (is_scalar($constant)) {
                return (string) $constant;
            }
        }

        return '';
    }

    public static function getBaseUrl(): string
    {
        $url = self::env('ZENMUX_BASE_URL');
        return $url === '' ? self::DEFAULT_BASE_URL : rtrim($url, '/');
    }

    public static function getDefaultModelId(): string
    {
        $model = self::env('ZENMUX_DEFAULT_MODEL');
        return $model === '' ? self::DEFAULT_MODEL : $model;
    }

    public static function getImageModelId(): string
    {
        $model = self::env('ZENMUX_IMAGE_MODEL');
        return $model === '' ? self::DEFAULT_IMAGE_MODEL : $model;
    }

    public static function getRequestTimeout(): float
    {
        $value = self::env('ZENMUX_REQUEST_TIMEOUT');
        return $value === '' ? 120.0 : max(1.0, (float) $value);
    }

    public static function getConnectTimeout(): float
    {
        $value = self::env('ZENMUX_CONNECT_TIMEOUT');
        return $value === '' ? 10.0 : max(1.0, (float) $value);
    }

    public static function hasCredentials(): bool
    {
        if (!class_exists(AiClient::class)) {
            return false;
        }

        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider(self::PROVIDER_ID)) {
            return false;
        }

        return $registry->getProviderRequestAuthentication(self::PROVIDER_ID) !== null;
    }

    public static function createRequestOptions(): RequestOptions
    {
        $options = new RequestOptions();
        $options->setTimeout(self::getRequestTimeout());
        $options->setConnectTimeout(self::getConnectTimeout());
        return $options;
    }

    public static function getUserAgent(): string
    {
        return 'ai-provider-for-zenmux/' . self::VERSION;
    }
}
