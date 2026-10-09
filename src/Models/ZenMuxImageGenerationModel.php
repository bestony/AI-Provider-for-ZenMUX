<?php

/**
 * ZenMUX image generation and editing model.
 *
 * ZenMUX's Images API is OpenAI-compatible but supports a JSON editing variant: when the prompt
 * carries input images, the model posts to `images/edits` with an `images` array of image URLs or
 * data URIs instead of the multipart upload. One model class covers both paths so the AI Client
 * can route reference-image ("refinement") prompts through the declared input modalities.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use ZenMux\AiProvider\Util\ZenMuxModelCatalog;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

class ZenMuxImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    use ZenMuxRequestTrait;

    private const GENERATION_PATH = 'images/generations';
    private const EDIT_PATH = 'images/edits';
    private const MAX_INPUT_IMAGES = 16;
    private const SQUARE_SIZE = '1024x1024';
    private const LANDSCAPE_SIZE = '1536x1024';
    private const PORTRAIT_SIZE = '1024x1536';

    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        [$text, $inputImages] = $this->preparePrompt($prompt);

        if ($inputImages === []) {
            $request = $this->createRequest(
                HttpMethodEnum::POST(),
                self::GENERATION_PATH,
                ['Content-Type' => 'application/json'],
                $this->prepareGenerateParams($text)
            );
        } else {
            if (!$this->supportsImageEdit()) {
                throw new InvalidArgumentException(
                    'This ZenMUX image model does not support image editing.'
                );
            }

            if (count($inputImages) > self::MAX_INPUT_IMAGES) {
                throw new InvalidArgumentException(
                    esc_html(
                        sprintf(
                            'ZenMUX image editing supports at most %d input images.',
                            self::MAX_INPUT_IMAGES
                        )
                    )
                );
            }

            $request = $this->createRequest(
                HttpMethodEnum::POST(),
                self::EDIT_PATH,
                ['Content-Type' => 'application/json'],
                $this->prepareEditParams($text, $inputImages)
            );
        }

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Validates the prompt and separates its text from its input images.
     *
     * @param list<Message> $prompt Prompt messages.
     * @return array{0: string, 1: list<File>} Prompt text and input images.
     */
    private function preparePrompt(array $prompt): array
    {
        if (count($prompt) !== 1 || !$prompt[0] instanceof Message || !$prompt[0]->getRole()->isUser()) {
            throw new InvalidArgumentException('ZenMUX image generation requires exactly one user message.');
        }

        $text = null;
        $images = [];
        foreach ($prompt[0]->getParts() as $part) {
            $partText = $part->getText();
            if ($text === null && $partText !== null && $partText !== '') {
                $text = $partText;
            }

            $file = $part->getFile();
            if ($file instanceof File && $file->isImage()) {
                $images[] = $file;
            }
        }

        if ($text === null) {
            throw new InvalidArgumentException('ZenMUX image generation requires a text prompt.');
        }

        return [$text, $images];
    }

    /**
     * Whether this model declares combined text and image input, which marks it as editable.
     */
    private function supportsImageEdit(): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $option) {
            if (!$option->getName()->isInputModalities()) {
                continue;
            }

            foreach ($option->getSupportedValues() ?? [] as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $types = array_map(static fn($modality) => $modality->value, $value);
                if (in_array('text', $types, true) && in_array('image', $types, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $text Prompt text.
     * @return array<string, mixed> Request body for `images/generations`.
     */
    private function prepareGenerateParams(string $text): array
    {
        $params = [
            'model' => $this->metadata()->getId(),
            'prompt' => $text,
            'response_format' => $this->wantsRemoteOutput() ? 'url' : 'b64_json',
        ];

        $candidateCount = $this->getConfig()->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        $size = $this->prepareSize();
        if ($size !== null) {
            $params['size'] = $size;
        }

        $outputFormat = $this->prepareOutputFormat();
        if ($outputFormat !== null) {
            $params['output_format'] = $outputFormat;
        }

        return $this->applyCustomOptions($params);
    }

    /**
     * @param string $text Prompt text.
     * @param list<File> $images Input images.
     * @return array<string, mixed> Request body for `images/edits`.
     */
    private function prepareEditParams(string $text, array $images): array
    {
        $params = [
            'model' => $this->metadata()->getId(),
            'prompt' => $text,
            'images' => array_map(
                static function (File $file): array {
                    $reference = $file->isRemote() ? $file->getUrl() : $file->getDataUri();
                    if (!is_string($reference) || $reference === '') {
                        throw new InvalidArgumentException(
                            'A ZenMUX input image could not be converted to a URL or data URI.'
                        );
                    }

                    return ['image_url' => $reference];
                },
                $images
            ),
        ];

        $candidateCount = $this->getConfig()->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        $size = $this->prepareSize();
        if ($size !== null) {
            $params['size'] = $size;
        }

        $outputFormat = $this->prepareOutputFormat();
        if ($outputFormat !== null) {
            $params['output_format'] = $outputFormat;
        }

        $customOptions = $this->getConfig()->getCustomOptions();
        if (array_key_exists('mask', $customOptions)) {
            $params['mask'] = $this->prepareMask($customOptions['mask']);
            unset($customOptions['mask']);
        }

        foreach ($customOptions as $key => $value) {
            if (array_key_exists($key, $params)) {
                throw new InvalidArgumentException(
                    esc_html(sprintf('The custom option "%s" conflicts with an existing parameter.', $key))
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * @param mixed $mask Mask custom option value: an image URL, data URI, or reference array.
     * @return array<string, mixed> Mask reference for the edits request.
     */
    private function prepareMask($mask): array
    {
        if (is_string($mask) && $mask !== '') {
            return ['image_url' => $mask];
        }

        if (
            is_array($mask)
            && (
                (isset($mask['image_url']) && is_string($mask['image_url']))
                || (isset($mask['file_id']) && is_string($mask['file_id']))
            )
        ) {
            return $mask;
        }

        throw new InvalidArgumentException(
            'The ZenMUX mask option must be an image URL, data URI, or a reference array with image_url or file_id.'
        );
    }

    /**
     * @param array<string, mixed> $params Generated parameters.
     * @return array<string, mixed> Parameters with custom options applied.
     */
    private function applyCustomOptions(array $params): array
    {
        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (array_key_exists($key, $params)) {
                throw new InvalidArgumentException(
                    esc_html(sprintf('The custom option "%s" conflicts with an existing parameter.', $key))
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    private function wantsRemoteOutput(): bool
    {
        $outputFileType = $this->getConfig()->getOutputFileType();
        return $outputFileType !== null && $outputFileType->isRemote();
    }

    /**
     * Maps the requested output MIME type to ZenMUX's `output_format`, which is documented for the
     * OpenAI GPT image family only.
     */
    private function prepareOutputFormat(): ?string
    {
        $outputMimeType = $this->getConfig()->getOutputMimeType();
        if (
            $outputMimeType === null
            || !ZenMuxModelCatalog::supportsImageOutputFormat($this->metadata()->getId())
        ) {
            return null;
        }

        $format = strtolower((string) preg_replace('#^image/#', '', $outputMimeType));
        return in_array($format, ['png', 'jpeg', 'webp'], true) ? $format : null;
    }

    /**
     * Maps orientation or aspect ratio to a size ZenMUX accepts, or null to use the model default.
     */
    private function prepareSize(): ?string
    {
        $config = $this->getConfig();
        $aspectRatio = $config->getOutputMediaAspectRatio();
        if ($aspectRatio !== null) {
            return $this->sizeForAspectRatio($aspectRatio);
        }

        $orientation = $config->getOutputMediaOrientation();
        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return self::LANDSCAPE_SIZE;
            }
            if ($orientation->isPortrait()) {
                return self::PORTRAIT_SIZE;
            }
            return self::SQUARE_SIZE;
        }

        return null;
    }

    private function sizeForAspectRatio(string $aspectRatio): string
    {
        $parts = explode(':', $aspectRatio);
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            $left = (float) $parts[0];
            $right = (float) $parts[1];
            if ($left > $right) {
                return self::LANDSCAPE_SIZE;
            }
            if ($left < $right) {
                return self::PORTRAIT_SIZE;
            }
        }

        return self::SQUARE_SIZE;
    }

    private function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $data = $response->getData();
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || $data['data'] === []) {
            throw ResponseException::fromMissingData('ZenMUX', 'data');
        }

        $mimeType = $this->expectedMimeType($data);
        $candidates = [];
        foreach ($data['data'] as $index => $entry) {
            $file = $this->parseImageEntry($entry, $index, $mimeType);
            $candidates[] = new Candidate(
                new Message(MessageRoleEnum::model(), [new MessagePart($file)]),
                FinishReasonEnum::stop()
            );
        }

        $id = isset($data['created']) && is_int($data['created']) ? 'img-' . $data['created'] : '';

        $usageData = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : null;
        $tokenUsage = $usageData === null
            ? new TokenUsage(0, 0, 0)
            : new TokenUsage(
                (int) ($usageData['input_tokens'] ?? 0),
                (int) ($usageData['output_tokens'] ?? 0),
                (int) ($usageData['total_tokens'] ?? 0)
            );

        $additionalData = $data;
        unset($additionalData['data'], $additionalData['usage']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * @param array<string, mixed> $data Decoded response body.
     */
    private function expectedMimeType(array $data): string
    {
        $format = isset($data['output_format']) && is_string($data['output_format'])
            ? strtolower($data['output_format'])
            : 'png';

        return in_array($format, ['png', 'jpeg', 'webp'], true) ? 'image/' . $format : 'image/png';
    }

    /**
     * @param mixed $entry Single response data entry.
     * @param int $index Entry index for error messages.
     * @param string $mimeType MIME type of the generated images.
     */
    private function parseImageEntry($entry, int $index, string $mimeType): File
    {
        if (!is_array($entry)) {
            throw ResponseException::fromInvalidData(
                'ZenMUX',
                esc_html("data[{$index}]"),
                'The value must be an object.'
            );
        }

        if (isset($entry['b64_json']) && is_string($entry['b64_json']) && $entry['b64_json'] !== '') {
            return new File($entry['b64_json'], $mimeType);
        }

        $url = isset($entry['url']) && is_string($entry['url']) ? $entry['url'] : null;
        if ($url !== null && $url !== '') {
            if (preg_match('#^https?://#i', $url) !== 1) {
                throw ResponseException::fromInvalidData(
                    'ZenMUX',
                    esc_html("data[{$index}].url"),
                    'The value must be an absolute HTTP(S) URL.'
                );
            }

            return new File($url, $mimeType);
        }

        throw ResponseException::fromInvalidData(
            'ZenMUX',
            esc_html("data[{$index}]"),
            'The value must contain either a url or b64_json string.'
        );
    }
}
