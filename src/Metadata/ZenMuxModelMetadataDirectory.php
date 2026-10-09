<?php

/**
 * ZenMUX model metadata directory.
 *
 * The catalog is derived from `GET /models`, whose entries carry the authoritative modality
 * metadata used to classify each model. The cache key includes the configuration that affects
 * the result so a configuration change is visible without waiting for the AI Client's default
 * 24-hour metadata cache to expire.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Metadata;

if (!defined('ABSPATH')) {
    exit;
}

use ZenMux\AiProvider\Provider\ZenMuxProvider;
use ZenMux\AiProvider\Util\ZenMuxConfig;
use ZenMux\AiProvider\Util\ZenMuxModelCatalog;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

class ZenMuxModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $headers['User-Agent'] = ZenMuxConfig::getUserAgent();
        return new Request(
            $method,
            ZenMuxProvider::url($path),
            $headers,
            $data,
            ZenMuxConfig::createRequestOptions()
        );
    }

    /**
     * Include the configuration that changes the catalog in the SDK cache key.
     */
    protected function getBaseCacheKey(): string
    {
        return parent::getBaseCacheKey() . '_' . md5(
            ZenMuxConfig::getBaseUrl()
            . '|' . ZenMuxConfig::getDefaultModelId()
            . '|' . ZenMuxConfig::getImageModelId()
        );
    }

    protected function parseResponseToModelMetadataList(Response $response): array
    {
        $data = $response->getData();
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || $data['data'] === []) {
            throw ResponseException::fromMissingData('ZenMUX', 'data');
        }

        $models = [];
        $modelDataById = [];
        foreach ($data['data'] as $modelData) {
            if (
                !is_array($modelData)
                || !isset($modelData['id'])
                || !is_string($modelData['id'])
                || $modelData['id'] === ''
            ) {
                continue;
            }

            $modelId = $modelData['id'];
            $models[$modelId] = $this->createModelMetadata($modelId, $modelData);
            $modelDataById[$modelId] = $modelData;
        }

        $preferred = ZenMuxConfig::getDefaultModelId();
        uksort(
            $models,
            static function (string $first, string $second) use ($preferred, $modelDataById): int {
                $firstPreferred = $first === $preferred ? 0 : 1;
                $secondPreferred = $second === $preferred ? 0 : 1;
                if ($firstPreferred !== $secondPreferred) {
                    return $firstPreferred <=> $secondPreferred;
                }

                return ZenMuxModelCatalog::compareModels($modelDataById[$first], $modelDataById[$second]);
            }
        );

        return array_values($models);
    }

    /**
     * @param string $modelId Model identifier.
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return ModelMetadata Model metadata.
     */
    private function createModelMetadata(string $modelId, array $modelData): ModelMetadata
    {
        $name = isset($modelData['display_name'])
            && is_string($modelData['display_name'])
            && trim($modelData['display_name']) !== ''
            ? $modelData['display_name']
            : $modelId;

        if (ZenMuxModelCatalog::isImageModel($modelData)) {
            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::imageGeneration()],
                $this->createImageOptions($modelId, $modelData)
            );
        }

        if (ZenMuxModelCatalog::isTextModel($modelData)) {
            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                $this->createTextOptions($modelData)
            );
        }

        return new ModelMetadata($modelId, $name, [], []);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return list<SupportedOption> Supported options for a text model.
     */
    private function createTextOptions(array $modelData): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        if (ZenMuxModelCatalog::supportsImageInput($modelData)) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        return [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::candidateCount(), [1]),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
        ];
    }

    /**
     * @param string $modelId Model identifier.
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return list<SupportedOption> Supported options for an image model.
     */
    private function createImageOptions(string $modelId, array $modelData): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        if (ZenMuxModelCatalog::supportsImageEdit($modelId, $modelData)) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        $options = [
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputFileType(), [
                FileTypeEnum::inline(),
                FileTypeEnum::remote(),
            ]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        if (ZenMuxModelCatalog::supportsImageOutputFormat($modelId)) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), [
                'image/png',
                'image/jpeg',
                'image/webp',
            ]);
        }

        return $options;
    }
}
