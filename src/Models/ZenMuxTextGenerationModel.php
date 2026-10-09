<?php

/**
 * ZenMUX OpenAI-compatible text generation model.
 *
 * ZenMUX diverges from the OpenAI chat completions specification in a few places handled here:
 * `max_tokens` is rejected in favor of `max_completion_tokens`, `modalities` is not supported,
 * JSON schemas require the named `{name, schema}` wrapper, and reasoning text is returned in
 * `reasoning` rather than the SDK's expected `reasoning_content`.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

class ZenMuxTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    use ZenMuxRequestTrait;

    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        if (isset($params['max_tokens'])) {
            $params['max_completion_tokens'] = $params['max_tokens'];
            unset($params['max_tokens']);
        }

        // ZenMUX rejects `modalities`; chat completions always produce text here.
        unset($params['modalities']);

        if (isset($params['response_format']) && $params['response_format'] === []) {
            unset($params['response_format']);
        }

        return $params;
    }

    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if (!is_array($outputSchema)) {
            return ['type' => 'json_object'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'zenmux_response',
                'schema' => $outputSchema,
            ],
        ];
    }

    protected function parseResponseChoiceMessageParts(array $messageData, int $index): array
    {
        /*
         * The SDK reads `reasoning_content`; ZenMUX documents `reasoning`. Reuse the parent's
         * thought-part handling by normalizing the field when the SDK field is absent.
         */
        if (
            !isset($messageData['reasoning_content'])
            && isset($messageData['reasoning'])
            && is_string($messageData['reasoning'])
        ) {
            $messageData['reasoning_content'] = $messageData['reasoning'];
        }

        return parent::parseResponseChoiceMessageParts($messageData, $index);
    }
}
