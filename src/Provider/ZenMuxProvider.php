<?php

/**
 * ZenMUX provider class.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Provider;

if (!defined('ABSPATH')) {
    exit;
}

use ZenMux\AiProvider\Metadata\ZenMuxModelMetadataDirectory;
use ZenMux\AiProvider\Models\ZenMuxImageGenerationModel;
use ZenMux\AiProvider\Models\ZenMuxTextGenerationModel;
use ZenMux\AiProvider\Util\ZenMuxConfig;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

class ZenMuxProvider extends AbstractApiProvider
{
    protected static function baseUrl(): string
    {
        return ZenMuxConfig::getBaseUrl();
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                $model = new ZenMuxTextGenerationModel($modelMetadata, $providerMetadata);
            } elseif ($capability->isImageGeneration()) {
                $model = new ZenMuxImageGenerationModel($modelMetadata, $providerMetadata);
            } else {
                continue;
            }

            /*
             * ZenMUX requests routinely run for tens of seconds. Without this the request is sent
             * with WordPress' short default timeout and fails.
             */
            $model->setRequestOptions(ZenMuxConfig::createRequestOptions());

            return $model;
        }

        throw new RuntimeException(
            esc_html(
                sprintf(
                    'The model "%s" has no supported capability for ZenMUX.',
                    $modelMetadata->getId()
                )
            )
        );
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            ZenMuxConfig::PROVIDER_ID,
            'ZenMUX',
            ProviderTypeEnum::cloud(),
            'https://zenmux.ai/platform/pay-as-you-go',
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                ? __('Text, vision and image generation with ZenMUX models.', 'ai-provider-for-zenmux')
                : 'Text, vision and image generation with ZenMUX models.';
        }

        // Provider logoPath support was added in SDK 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $args[] = dirname(__DIR__, 2) . '/assets/images/zenmux.svg';
        }

        return new ProviderMetadata(...$args);
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ListModelsApiBasedProviderAvailability(static::modelMetadataDirectory());
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ZenMuxModelMetadataDirectory();
    }
}
