<?php

/**
 * ZenMUX model preference helpers.
 *
 * These back the `wpai_preferred_*` filters. They only act when ZenMUX has credentials and keep
 * every entry supplied by other providers.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Util;

if (!defined('ABSPATH')) {
    exit;
}

final class ZenMuxPreferences
{
    /**
     * Puts a configured model first while retaining entries supplied by other providers.
     *
     * @param mixed  $preferredModels Existing [provider, model] tuples.
     * @param string $modelId The model to prefer.
     * @return array<int, array{string, string}> Filtered preference tuples.
     */
    public static function preferModel($preferredModels, string $modelId): array
    {
        $preferred = is_array($preferredModels) ? array_values($preferredModels) : [];
        if (!ZenMuxConfig::hasCredentials() || $modelId === '') {
            return $preferred;
        }

        $result = [[ZenMuxConfig::PROVIDER_ID, $modelId]];
        foreach ($preferred as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }

            $entry = array_values($entry);
            if (!is_scalar($entry[0]) || !is_scalar($entry[1])) {
                continue;
            }

            if ((string) $entry[0] === ZenMuxConfig::PROVIDER_ID && (string) $entry[1] === $modelId) {
                continue;
            }

            $result[] = [(string) $entry[0], (string) $entry[1]];
        }

        return $result;
    }

    /**
     * @param mixed $preferredModels Existing preference tuples.
     * @return array<int, array{string, string}> Updated tuples.
     */
    public static function preferTextModels($preferredModels): array
    {
        return self::preferModel($preferredModels, ZenMuxConfig::getDefaultModelId());
    }

    /**
     * @param mixed $preferredModels Existing preference tuples.
     * @return array<int, array{string, string}> Updated tuples.
     */
    public static function preferVisionModels($preferredModels): array
    {
        return self::preferModel($preferredModels, ZenMuxConfig::getDefaultModelId());
    }

    /**
     * @param mixed $preferredModels Existing preference tuples.
     * @return array<int, array{string, string}> Updated tuples.
     */
    public static function preferImageModels($preferredModels): array
    {
        return self::preferModel($preferredModels, ZenMuxConfig::getImageModelId());
    }
}
