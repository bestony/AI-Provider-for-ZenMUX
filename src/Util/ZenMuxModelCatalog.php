<?php

/**
 * ZenMUX model classification helpers.
 *
 * ZenMUX's model list carries authoritative modality metadata, so classification is derived from
 * `input_modalities` and `output_modalities` instead of hardcoded model ID patterns. The helpers
 * are pure functions over decoded `/models` entries so they can be checked without WordPress.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Util;

if (!defined('ABSPATH')) {
    exit;
}

final class ZenMuxModelCatalog
{
    /**
     * Normalize a modality field into a lowercase string list.
     *
     * @param mixed $value Raw modality value.
     * @return list<string> Normalized modalities.
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = strtolower($item);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return list<string> Input modalities.
     */
    public static function inputModalities(array $modelData): array
    {
        return self::stringList($modelData['input_modalities'] ?? []);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return list<string> Output modalities.
     */
    public static function outputModalities(array $modelData): array
    {
        return self::stringList($modelData['output_modalities'] ?? []);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return bool Whether the model produces text.
     */
    public static function isTextModel(array $modelData): bool
    {
        return in_array('text', self::outputModalities($modelData), true);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return bool Whether the model produces images.
     */
    public static function isImageModel(array $modelData): bool
    {
        return in_array('image', self::outputModalities($modelData), true);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return bool Whether the model accepts image input.
     */
    public static function supportsImageInput(array $modelData): bool
    {
        return in_array('image', self::inputModalities($modelData), true);
    }

    /**
     * Whether the model ID belongs to the documented OpenAI GPT image family.
     *
     * @param string $modelId Model identifier.
     * @return bool Whether the model is a documented GPT image model.
     */
    public static function isGptImageFamily(string $modelId): bool
    {
        return preg_match('#(?:^|/)gpt-image(?:$|[-.])#i', $modelId) === 1;
    }

    /**
     * Whether the model can edit images: image output plus image input, or the documented GPT
     * image family, which supports edits even when the catalog omits its input modalities.
     *
     * @param string $modelId Model identifier.
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return bool Whether the model supports image editing.
     */
    public static function supportsImageEdit(string $modelId, array $modelData): bool
    {
        if (!self::isImageModel($modelData)) {
            return false;
        }

        return self::supportsImageInput($modelData) || self::isGptImageFamily($modelId);
    }

    /**
     * Whether the model accepts the `output_format` parameter, which ZenMUX documents for GPT
     * image models only.
     *
     * @param string $modelId Model identifier.
     * @return bool Whether the model supports output format selection.
     */
    public static function supportsImageOutputFormat(string $modelId): bool
    {
        return self::isGptImageFamily($modelId);
    }

    /**
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return bool Whether the model claims no supported capability.
     */
    public static function isUnsupported(array $modelData): bool
    {
        return !self::isTextModel($modelData) && !self::isImageModel($modelData);
    }

    /**
     * @param string $modelId Model identifier.
     * @return bool Whether the model looks like a preview, beta, or experimental release.
     */
    public static function isPreview(string $modelId): bool
    {
        return stripos($modelId, 'preview') !== false
            || stripos($modelId, 'beta') !== false
            || stripos($modelId, '-exp') !== false;
    }

    /**
     * Sort tier for catalog ordering: stable text models first, then previews, image models, and
     * models without any supported capability.
     *
     * @param array<string, mixed> $modelData Decoded `/models` entry.
     * @return int Sort tier.
     */
    public static function sortTier(array $modelData): int
    {
        if (self::isUnsupported($modelData)) {
            return 3;
        }

        if (self::isImageModel($modelData)) {
            return 2;
        }

        $modelId = isset($modelData['id']) && is_string($modelData['id']) ? $modelData['id'] : '';
        if (self::isPreview($modelId)) {
            return 1;
        }

        return 0;
    }

    /**
     * Compares two decoded `/models` entries for catalog ordering.
     *
     * @param array<string, mixed> $modelA First entry.
     * @param array<string, mixed> $modelB Second entry.
     * @return int Comparison result.
     */
    public static function compareModels(array $modelA, array $modelB): int
    {
        $tier = self::sortTier($modelA) <=> self::sortTier($modelB);
        if ($tier !== 0) {
            return $tier;
        }

        $idA = isset($modelA['id']) && is_string($modelA['id']) ? $modelA['id'] : '';
        $idB = isset($modelB['id']) && is_string($modelB['id']) ? $modelB['id'] : '';

        return strnatcasecmp($idA, $idB);
    }
}
