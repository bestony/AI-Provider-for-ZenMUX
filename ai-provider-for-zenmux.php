<?php

/**
 * Plugin Name:       AI Provider for ZenMUX
 * Plugin URI:        https://github.com/bestony/AI-Provider-for-ZenMUX
 * Description:       ZenMUX provider for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           1.0.0
 * Author:            Bestony
 * Author URI:        https://github.com/bestony
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-zenmux
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider;

use ZenMux\AiProvider\Provider\ZenMuxProvider;
use ZenMux\AiProvider\Util\ZenMuxPreferences;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Register the ZenMUX provider before the Connectors screen builds its cards.
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();
    if ($registry->hasProvider(ZenMuxProvider::class)) {
        return;
    }

    $registry->registerProvider(ZenMuxProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Prefer the configured ZenMUX text model while preserving other providers' entries.
 *
 * @param mixed $preferredModels Existing [provider, model] tuples.
 * @return array<int, array{string, string}> Updated tuples.
 */
function prefer_text_models($preferredModels): array
{
    return ZenMuxPreferences::preferTextModels($preferredModels);
}

/**
 * @param mixed $preferredModels Existing [provider, model] tuples.
 * @return array<int, array{string, string}> Updated tuples.
 */
function prefer_vision_models($preferredModels): array
{
    return ZenMuxPreferences::preferVisionModels($preferredModels);
}

/**
 * @param mixed $preferredModels Existing [provider, model] tuples.
 * @return array<int, array{string, string}> Updated tuples.
 */
function prefer_image_models($preferredModels): array
{
    return ZenMuxPreferences::preferImageModels($preferredModels);
}

add_filter('wpai_preferred_text_models', __NAMESPACE__ . '\\prefer_text_models');
add_filter('wpai_preferred_vision_models', __NAMESPACE__ . '\\prefer_vision_models');
add_filter('wpai_preferred_image_models', __NAMESPACE__ . '\\prefer_image_models');
