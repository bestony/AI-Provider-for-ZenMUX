<?php

// phpcs:ignoreFile -- dev-only CLI harness; .gitattributes export-ignores it from releases.

/**
 * WordPress-free self-check for the ZenMUX provider.
 *
 * Run `php scripts/selfcheck.php` for configuration checks. Add `--sdk=<path>` pointing at a
 * php-ai-client checkout or the bundled WordPress copy to exercise catalog parsing, request
 * construction, response parsing, and registry credential checks.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit; // Exit if accessed directly.
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/src/autoload.php';

use ZenMux\AiProvider\Util\ZenMuxConfig;
use ZenMux\AiProvider\Util\ZenMuxModelCatalog;

/*
 * The provider escapes exception messages with WordPress escaping functions. This harness runs
 * without WordPress, so provide the one the checked code paths call.
 */
if (!function_exists('esc_html')) {
    /**
     * @param mixed $text Text to escape.
     */
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

$checks = 0;
$failures = 0;

/**
 * Records the outcome of a single check.
 *
 * @param bool   $condition   Whether the check passed.
 * @param string $description Human-readable description.
 * @return void
 */
function check(bool $condition, string $description): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        fwrite(STDOUT, "ok    {$description}\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "FAIL  {$description}\n");
}

// Start from a clean configuration slate.
foreach (['ZENMUX_BASE_URL', 'ZENMUX_DEFAULT_MODEL', 'ZENMUX_IMAGE_MODEL', 'ZENMUX_REQUEST_TIMEOUT', 'ZENMUX_CONNECT_TIMEOUT'] as $name) {
    putenv($name);
}

// --- Configuration -----------------------------------------------------------------------------
check(ZenMuxConfig::getBaseUrl() === 'https://zenmux.ai/api/v1', 'default base URL');
check(ZenMuxConfig::getDefaultModelId() === 'openai/gpt-5', 'default text model');
check(ZenMuxConfig::getImageModelId() === 'openai/gpt-image-2', 'default image model');
check(ZenMuxConfig::getRequestTimeout() === 120.0, 'default request timeout');
check(ZenMuxConfig::getConnectTimeout() === 10.0, 'default connect timeout');
check(ZenMuxConfig::getUserAgent() === 'ai-provider-for-zenmux/1.0.0', 'user agent contains plugin version');
check(!ZenMuxConfig::hasCredentials(), 'without the AI Client there are no credentials to report');

putenv('ZENMUX_BASE_URL=https://gateway.example.test/v1/');
check(ZenMuxConfig::getBaseUrl() === 'https://gateway.example.test/v1', 'base URL override drops the trailing slash');
putenv('ZENMUX_DEFAULT_MODEL=example/text-model');
check(ZenMuxConfig::getDefaultModelId() === 'example/text-model', 'text model override');
putenv('ZENMUX_IMAGE_MODEL=example/image-model');
check(ZenMuxConfig::getImageModelId() === 'example/image-model', 'image model override');
putenv('ZENMUX_REQUEST_TIMEOUT=45');
check(ZenMuxConfig::getRequestTimeout() === 45.0, 'request timeout override');
putenv('ZENMUX_CONNECT_TIMEOUT=3');
check(ZenMuxConfig::getConnectTimeout() === 3.0, 'connect timeout override');

foreach (['ZENMUX_BASE_URL', 'ZENMUX_DEFAULT_MODEL', 'ZENMUX_IMAGE_MODEL', 'ZENMUX_REQUEST_TIMEOUT', 'ZENMUX_CONNECT_TIMEOUT'] as $name) {
    putenv($name);
}

// --- Model catalog -----------------------------------------------------------------------------
$textModel = ['id' => 'openai/gpt-5', 'input_modalities' => ['text', 'image'], 'output_modalities' => ['text']];
$imageModel = ['id' => 'example/image-1', 'input_modalities' => ['text'], 'output_modalities' => ['image']];
$editModel = ['id' => 'example/image-edit', 'input_modalities' => ['text', 'image'], 'output_modalities' => ['image']];
$gptImage = ['id' => 'openai/gpt-image-2', 'input_modalities' => ['text'], 'output_modalities' => ['image']];
$videoModel = ['id' => 'example/video-1', 'input_modalities' => ['text'], 'output_modalities' => ['video']];
$previewModel = ['id' => 'example/chat-preview', 'input_modalities' => ['text'], 'output_modalities' => ['text']];

check(ZenMuxModelCatalog::isTextModel($textModel) && !ZenMuxModelCatalog::isImageModel($textModel), 'text output classifies as text generation');
check(ZenMuxModelCatalog::supportsImageInput($textModel), 'image input marks a text model as vision capable');
check(!ZenMuxModelCatalog::supportsImageInput(['input_modalities' => ['text']]), 'text-only models stay text-only');
check(ZenMuxModelCatalog::isImageModel($imageModel) && !ZenMuxModelCatalog::isTextModel($imageModel), 'image output classifies as image generation');
check(!ZenMuxModelCatalog::supportsImageEdit($imageModel['id'], $imageModel), 'generation-only image models are not editable');
check(ZenMuxModelCatalog::supportsImageEdit($editModel['id'], $editModel), 'image input makes an image model editable');
check(ZenMuxModelCatalog::supportsImageEdit($gptImage['id'], $gptImage), 'the documented GPT image family is editable');
check(ZenMuxModelCatalog::supportsImageOutputFormat('openai/gpt-image-1.5'), 'GPT image models support output format selection');
check(!ZenMuxModelCatalog::supportsImageOutputFormat('example/image-1'), 'other image models do not claim output format support');
check(ZenMuxModelCatalog::isUnsupported($videoModel), 'video-only models claim no capability');
check(ZenMuxModelCatalog::isUnsupported(['output_modalities' => ['audio']]), 'audio-only models claim no capability');
check(ZenMuxModelCatalog::isPreview($previewModel['id']), 'preview models are recognised');
check(!ZenMuxModelCatalog::isPreview($textModel['id']), 'stable models are not previews');
check(ZenMuxModelCatalog::sortTier($textModel) < ZenMuxModelCatalog::sortTier($previewModel), 'stable text models sort before previews');
check(ZenMuxModelCatalog::sortTier($previewModel) < ZenMuxModelCatalog::sortTier($imageModel), 'previews sort before image models');
check(ZenMuxModelCatalog::sortTier($imageModel) < ZenMuxModelCatalog::sortTier($videoModel), 'image models sort before unsupported models');
check(ZenMuxModelCatalog::compareModels(['id' => 'example/model-2'], ['id' => 'example/model-10']) < 0, 'natural ordering compares numeric segments');

// --- Optional SDK checks ------------------------------------------------------------------------
$sdkPath = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--sdk=') === 0) {
        $sdkPath = substr($argument, 6);
    }
}

if ($sdkPath !== null && load_sdk($sdkPath)) {
    use_sdk_checks();
} else {
    fwrite(STDOUT, "skip  SDK-dependent checks (pass --sdk=<path to php-ai-client> to run them)\n");
}

fwrite(STDOUT, sprintf("\n%d checks, %d failure(s)\n", $checks, $failures));
exit($failures === 0 ? 0 : 1);

/**
 * Registers the php-ai-client SDK classes, accepting either the upstream checkout layout or the
 * copy bundled with WordPress.
 *
 * @param string $sdkPath Path to the SDK (root or src directory).
 * @return bool Whether the SDK could be loaded.
 */
function load_sdk(string $sdkPath): bool
{
    if (is_file($sdkPath . '/autoload.php')) {
        require_once $sdkPath . '/autoload.php';
        return true;
    }

    if (is_file($sdkPath . '/polyfills.php')) {
        require_once $sdkPath . '/polyfills.php';
    }

    $source = is_dir($sdkPath . '/src') ? $sdkPath . '/src' : $sdkPath;
    if (!is_dir($source)) {
        return false;
    }

    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'WordPress\\AiClient\\';
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            return;
        }

        $file = $source . '/' . str_replace('\\', '/', substr($class, $length)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    return class_exists('WordPress\\AiClient\\AiClient');
}

/**
 * Reads the supported values of a named model option.
 *
 * @param \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $metadata Model metadata.
 * @param \WordPress\AiClient\Providers\Models\Enums\OptionEnum  $name Option name.
 * @return array|null Supported values, or null when the option is absent.
 */
function optionValues(
    \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $metadata,
    \WordPress\AiClient\Providers\Models\Enums\OptionEnum $name
): ?array {
    foreach ($metadata->getSupportedOptions() as $option) {
        if ($option->getName()->equals($name)) {
            return $option->getSupportedValues();
        }
    }

    return null;
}

/**
 * Exercises the provider against the real SDK classes with a fake transport.
 *
 * @return void
 */
function use_sdk_checks(): void
{
    $provider = \ZenMux\AiProvider\Provider\ZenMuxProvider::class;

    $transporter = new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
        /** @var list<\WordPress\AiClient\Providers\Http\DTO\Request> */
        public $requests = [];

        /** @var callable|null */
        public $responder = null;

        public function send(
            \WordPress\AiClient\Providers\Http\DTO\Request $request,
            ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null
        ): \WordPress\AiClient\Providers\Http\DTO\Response {
            $this->requests[] = $request;
            if ($this->responder !== null) {
                return ($this->responder)($request);
            }

            return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
                'id' => 'chatcmpl-selfcheck',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
            ]));
        }
    };

    $registry = \WordPress\AiClient\AiClient::defaultRegistry();
    $registry->setHttpTransporter($transporter);

    // --- Registration -------------------------------------------------------------------------
    if (!$registry->hasProvider($provider)) {
        $registry->registerProvider($provider);
    }
    // The main plugin file uses the same guard; re-running it must stay a no-op.
    if (!$registry->hasProvider($provider)) {
        $registry->registerProvider($provider);
    }
    check($registry->hasProvider('zenmux'), 'provider registers under the zenmux id');
    check($registry->getProviderClassName('zenmux') === $provider, 'the registration guard keeps the provider class');
    check(!ZenMuxConfig::hasCredentials(), 'a registered provider without a key reports no credentials');

    $preferenceInput = [['openai', 'gpt-5.6'], ['google', 'gemini-3.6-flash']];
    check(
        \ZenMux\AiProvider\Util\ZenMuxPreferences::preferTextModels($preferenceInput) === $preferenceInput,
        'an unconfigured provider leaves the preference filters unchanged'
    );

    $registry->setProviderRequestAuthentication(
        ZenMuxConfig::PROVIDER_ID,
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('selfcheck-token')
    );
    check(ZenMuxConfig::hasCredentials(), 'credential presence comes from the AI Client registry');

    check(
        \ZenMux\AiProvider\Util\ZenMuxPreferences::preferTextModels($preferenceInput) === [
            ['zenmux', 'openai/gpt-5'],
            ['openai', 'gpt-5.6'],
            ['google', 'gemini-3.6-flash'],
        ],
        'a credentialed provider prepends its default text model without dropping entries'
    );
    check(
        \ZenMux\AiProvider\Util\ZenMuxPreferences::preferVisionModels([['zenmux', 'openai/gpt-5']]) === [
            ['zenmux', 'openai/gpt-5'],
        ],
        'existing ZenMUX preference entries are deduplicated'
    );
    check(
        \ZenMux\AiProvider\Util\ZenMuxPreferences::preferImageModels([]) === [
            ['zenmux', 'openai/gpt-image-2'],
        ],
        'the image filter prepends the default image model'
    );

    // --- Provider metadata --------------------------------------------------------------------
    $metadata = $provider::metadata();
    check($metadata->getId() === 'zenmux' && $metadata->getName() === 'ZenMUX', 'provider metadata identifies ZenMUX');
    check(
        $metadata->getCredentialsUrl() === 'https://zenmux.ai/platform/pay-as-you-go',
        'credentials URL points at the ZenMUX key page'
    );
    $authentication = $metadata->getAuthenticationMethod();
    check($authentication !== null && $authentication->isApiKey(), 'provider uses API key authentication');
    check(
        $metadata->getDescription() !== null && $metadata->getDescription() !== '',
        'provider description is supplied on modern SDKs'
    );
    check($metadata->getLogoPath() !== null && is_file($metadata->getLogoPath()), 'provider logo asset exists');
    check($provider::url('models') === 'https://zenmux.ai/api/v1/models', 'provider URL joins the base URL and path');

    // --- Model catalog parsing ----------------------------------------------------------------
    $payload = [
        'object' => 'list',
        'data' => [
            ['id' => 'openai/gpt-5', 'display_name' => 'OpenAI: GPT-5', 'input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            ['id' => 'openai/gpt-image-2', 'display_name' => 'OpenAI: GPT Image 2', 'input_modalities' => ['text', 'image'], 'output_modalities' => ['image']],
            ['id' => 'example/image-1', 'display_name' => 'Example Image', 'input_modalities' => ['text'], 'output_modalities' => ['image']],
            ['id' => 'example/video-1', 'display_name' => 'Example Video', 'input_modalities' => ['text'], 'output_modalities' => ['video']],
            ['id' => '', 'display_name' => 'Broken entry'],
            'not-an-array',
            ['id' => 'example/chat-preview', 'input_modalities' => ['text'], 'output_modalities' => ['text']],
        ],
    ];
    $transporter->responder = static function () use ($payload): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode($payload));
    };

    $directory = $provider::modelMetadataDirectory();
    $models = $directory->listModelMetadata();
    check(count($models) === 5, 'invalid model entries are skipped');
    check(
        array_map(static fn($model) => $model->getId(), $models) === [
            'openai/gpt-5',
            'example/chat-preview',
            'example/image-1',
            'openai/gpt-image-2',
            'example/video-1',
        ],
        'the preferred model leads and the remaining models are tiered'
    );

    $requestsAfterFirstList = count($transporter->requests);
    check(
        $transporter->requests[0]->getUri() === 'https://zenmux.ai/api/v1/models',
        'model listing uses the models endpoint'
    );
    check(
        $transporter->requests[0]->getHeaderAsString('User-Agent') === 'ai-provider-for-zenmux/1.0.0',
        'model listing sends the plugin user agent'
    );

    $directory->listModelMetadata();
    check(count($transporter->requests) === $requestsAfterFirstList, 'a second listing is served from the metadata cache');

    putenv('ZENMUX_DEFAULT_MODEL=example/other-model');
    $directory->listModelMetadata();
    check(
        count($transporter->requests) === $requestsAfterFirstList + 1,
        'a default model change invalidates the metadata cache'
    );
    putenv('ZENMUX_DEFAULT_MODEL');

    $byId = [];
    foreach ($models as $model) {
        $byId[$model->getId()] = $model;
    }

    check(
        $byId['example/chat-preview']->getName() === 'example/chat-preview',
        'missing display names fall back to the model id'
    );
    check(
        array_map(static fn($capability) => $capability->value, $byId['openai/gpt-5']->getSupportedCapabilities())
            === ['text_generation', 'chat_history'],
        'text models expose text generation and chat history'
    );
    check(
        array_map(static fn($capability) => $capability->value, $byId['openai/gpt-image-2']->getSupportedCapabilities())
            === ['image_generation'],
        'image models expose image generation'
    );
    check($byId['example/video-1']->getSupportedCapabilities() === [], 'unsupported models claim no capability');

    $option = \WordPress\AiClient\Providers\Models\Enums\OptionEnum::class;
    check(optionValues($byId['openai/gpt-5'], $option::candidateCount()) === [1], 'text models accept a single candidate');
    check(
        in_array(
            [
                \WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
                \WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
            ],
            optionValues($byId['openai/gpt-5'], $option::inputModalities()) ?? [],
            true
        ),
        'vision text models declare image input'
    );
    check(
        optionValues($byId['openai/gpt-5'], $option::outputMimeType()) === ['text/plain', 'application/json'],
        'text models declare their output types'
    );

    check(
        in_array(
            [
                \WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
                \WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
            ],
            optionValues($byId['openai/gpt-image-2'], $option::inputModalities()) ?? [],
            true
        ),
        'GPT image models declare edit-capable image input'
    );
    check(
        optionValues($byId['openai/gpt-image-2'], $option::outputMimeType()) !== null,
        'GPT image models declare output format support'
    );

    // --- Text generation ----------------------------------------------------------------------
    $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]];
    $textModel = new \ZenMux\AiProvider\Models\ZenMuxTextGenerationModel(
        $byId['openai/gpt-5'],
        $provider::metadata()
    );
    $textModel->setHttpTransporter($transporter);
    $textModel->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('selfcheck-token')
    );
    $textModel->setRequestOptions(ZenMuxConfig::createRequestOptions());
    $textModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'maxTokens' => 64,
        'outputMimeType' => 'application/json',
        'outputSchema' => $schema,
    ]));

    $prompt = [
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart('Hello')]
        ),
    ];

    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'id' => 'gen-selfcheck',
            'choices' => [[
                'message' => ['role' => 'assistant', 'reasoning' => 'Think first.', 'content' => 'Answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ]));
    };

    $result = $textModel->generateTextResult($prompt);
    $request = $transporter->requests[count($transporter->requests) - 1];
    $body = json_decode((string) $request->getBody(), true);

    check($request->getUri() === 'https://zenmux.ai/api/v1/chat/completions', 'text requests go to chat completions');
    check(
        isset($body['max_completion_tokens']) && $body['max_completion_tokens'] === 64 && !isset($body['max_tokens']),
        'maximum tokens map to max_completion_tokens'
    );
    check(!isset($body['modalities']), 'the unsupported modalities field is never sent');
    check(
        ($body['response_format']['type'] ?? null) === 'json_schema'
            && ($body['response_format']['json_schema']['name'] ?? null) === 'zenmux_response'
            && ($body['response_format']['json_schema']['schema'] ?? null) === $schema,
        'structured output uses the named schema wrapper'
    );

    $messageParts = $result->getCandidates()[0]->getMessage()->getParts();
    check(
        count($messageParts) === 2
            && $messageParts[0]->getChannel()->isThought()
            && $messageParts[0]->getText() === 'Think first.'
            && $messageParts[1]->getText() === 'Answer.',
        'ZenMUX reasoning becomes a thought part before the answer'
    );

    $textModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputMimeType' => 'application/json',
    ]));
    $textModel->generateTextResult($prompt);
    $fallbackBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(
        ($fallbackBody['response_format']['type'] ?? null) === 'json_object',
        'JSON output without a schema falls back to json_object'
    );

    // --- Text contract details ----------------------------------------------------------------
    $userMessage = static function (string $text): \WordPress\AiClient\Messages\DTO\Message {
        return new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart($text)]
        );
    };

    $conversation = [
        $userMessage('Hello'),
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart('Hi')]
        ),
        $userMessage('Bye'),
    ];
    $textModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'systemInstruction' => 'Be brief.',
    ]));
    $textModel->generateTextResult($conversation);
    $conversationBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(
        array_map(static fn($message) => $message['role'], $conversationBody['messages'])
            === ['system', 'user', 'assistant', 'user'],
        'conversation history maps to OpenAI chat roles'
    );
    check(
        ($conversationBody['messages'][0]['content'][0]['text'] ?? null) === 'Be brief.',
        'system instructions are prepended as a system message'
    );

    $visionPrompt = [
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [
                new \WordPress\AiClient\Messages\DTO\MessagePart('Describe.'),
                new \WordPress\AiClient\Messages\DTO\MessagePart(
                    new \WordPress\AiClient\Files\DTO\File('https://example.test/cat.png')
                ),
                new \WordPress\AiClient\Messages\DTO\MessagePart(
                    new \WordPress\AiClient\Files\DTO\File(base64_encode('fake-image'), 'image/png')
                ),
            ]
        ),
    ];
    $textModel->setConfig(new \WordPress\AiClient\Providers\Models\DTO\ModelConfig());
    $textModel->generateTextResult($visionPrompt);
    $visionBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(
        ($visionBody['messages'][0]['content'][1]['image_url']['url'] ?? null) === 'https://example.test/cat.png',
        'remote image parts are sent as image URLs'
    );
    check(
        strpos($visionBody['messages'][0]['content'][2]['image_url']['url'] ?? '', 'data:image/png;base64,') === 0,
        'inline image parts are sent as data URIs'
    );

    $textModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'functionDeclarations' => [[
            'name' => 'get_weather',
            'description' => 'Get the weather.',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        ]],
    ]));
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'id' => 'gen-tools',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'type' => 'function',
                        'id' => 'call_2',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Rome"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6, 'total_tokens' => 11],
        ]));
    };
    $toolConversation = [
        $userMessage('Weather?'),
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Tools\DTO\FunctionCall('call_1', 'get_weather', ['city' => 'Paris'])
            )]
        ),
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Tools\DTO\FunctionResponse('call_1', 'get_weather', ['temp' => 20])
            )]
        ),
    ];
    $toolResult = $textModel->generateTextResult($toolConversation);
    $toolBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(
        ($toolBody['tools'][0]['function']['name'] ?? null) === 'get_weather',
        'function declarations are sent as OpenAI tools'
    );
    check(
        ($toolBody['messages'][2]['role'] ?? null) === 'tool'
            && ($toolBody['messages'][2]['tool_call_id'] ?? null) === 'call_1',
        'function responses are sent as tool messages with the call ID'
    );
    $toolParts = $toolResult->getCandidates()[0]->getMessage()->getParts();
    $functionCall = isset($toolParts[0]) ? $toolParts[0]->getFunctionCall() : null;
    check(
        $functionCall !== null
            && $functionCall->getName() === 'get_weather'
            && $functionCall->getArgs() === ['city' => 'Rome']
            && $toolResult->getCandidates()[0]->getFinishReason()->isToolCalls(),
        'returned tool calls are parsed into function call parts'
    );
    check(
        $toolResult->getTokenUsage()->getPromptTokens() === 5
            && $toolResult->getTokenUsage()->getCompletionTokens() === 6
            && $toolResult->getTokenUsage()->getTotalTokens() === 11,
        'token usage is reported from the response'
    );

    $unknownFinish = false;
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'x'],
                'finish_reason' => 'paused',
            ]],
        ]));
    };
    try {
        $textModel->generateTextResult([$userMessage('Again')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ResponseException $exception) {
        $unknownFinish = true;
    }
    check($unknownFinish, 'unknown finish reasons fail with a response error');

    $missingChoices = false;
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode(['id' => 'empty']));
    };
    try {
        $textModel->generateTextResult([$userMessage('Again')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ResponseException $exception) {
        $missingChoices = true;
    }
    check($missingChoices, 'responses without choices fail with a response error');

    $errorMessage = '';
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(
            401,
            [],
            json_encode(['error' => ['message' => 'Invalid API key']])
        );
    };
    try {
        $textModel->generateTextResult([$userMessage('Again')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ClientException $exception) {
        $errorMessage = $exception->getMessage();
    }
    check(strpos($errorMessage, 'Invalid API key') !== false, 'API errors surface the provider message');

    // --- Image generation ---------------------------------------------------------------------
    $imageModel = new \ZenMux\AiProvider\Models\ZenMuxImageGenerationModel(
        $byId['openai/gpt-image-2'],
        $provider::metadata()
    );
    $imageModel->setHttpTransporter($transporter);
    $imageModel->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('selfcheck-token')
    );
    $imageModel->setRequestOptions(ZenMuxConfig::createRequestOptions());

    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'created' => 1713833628,
            'output_format' => 'webp',
            'data' => [['b64_json' => base64_encode('fake-image')]],
            'usage' => ['input_tokens' => 7, 'output_tokens' => 8, 'total_tokens' => 15],
        ]));
    };

    $imageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'candidateCount' => 2,
        'outputFileType' => 'inline',
        'outputMediaOrientation' => 'landscape',
        'outputMimeType' => 'image/webp',
    ]));

    $imageResult = $imageModel->generateImageResult([$userMessage('A cat')]);
    $lastRequest = $transporter->requests[count($transporter->requests) - 1];
    $imageBody = json_decode((string) $lastRequest->getBody(), true);

    check(
        $lastRequest->getUri() === 'https://zenmux.ai/api/v1/images/generations',
        'image generation uses the generations endpoint'
    );
    check(
        ($imageBody['model'] ?? null) === 'openai/gpt-image-2' && ($imageBody['prompt'] ?? null) === 'A cat',
        'the image request carries the model and prompt'
    );
    check(($imageBody['n'] ?? null) === 2, 'the candidate count is sent as n');
    check(($imageBody['size'] ?? null) === '1536x1024', 'landscape orientation maps to a landscape size');
    check(($imageBody['response_format'] ?? null) === 'b64_json', 'inline requests ask for base64 output');
    check(
        ($imageBody['output_format'] ?? null) === 'webp',
        'supported models map the output MIME type to an output format'
    );

    $imageFile = $imageResult->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
    check(
        $imageFile instanceof \WordPress\AiClient\Files\DTO\File
            && $imageFile->isInline()
            && $imageFile->getMimeType() === 'image/webp',
        'base64 responses become inline image files with the reported format'
    );
    check($imageResult->getId() === 'img-1713833628', 'the created timestamp becomes the result id');
    check(
        $imageResult->getTokenUsage()->getPromptTokens() === 7
            && $imageResult->getTokenUsage()->getCompletionTokens() === 8
            && $imageResult->getTokenUsage()->getTotalTokens() === 15,
        'image usage is reported'
    );

    $imageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputFileType' => 'remote',
    ]));
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'created' => 1,
            'data' => [['url' => 'https://images.example.test/out.png']],
        ]));
    };
    $remoteResult = $imageModel->generateImageResult([$userMessage('A dog')]);
    $remoteBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(($remoteBody['response_format'] ?? null) === 'url', 'remote requests ask for URL output');
    $remoteFile = $remoteResult->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
    check(
        $remoteFile instanceof \WordPress\AiClient\Files\DTO\File
            && $remoteFile->isRemote()
            && $remoteFile->getUrl() === 'https://images.example.test/out.png',
        'URL responses become remote image files'
    );

    $plainImageModel = new \ZenMux\AiProvider\Models\ZenMuxImageGenerationModel(
        $byId['example/image-1'],
        $provider::metadata()
    );
    $plainImageModel->setHttpTransporter($transporter);
    $plainImageModel->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('selfcheck-token')
    );
    $plainImageModel->setRequestOptions(ZenMuxConfig::createRequestOptions());
    $plainImageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputMimeType' => 'image/webp',
    ]));
    $plainImageModel->generateImageResult([$userMessage('A bird')]);
    $plainBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(
        !isset($plainBody['output_format']),
        'models without documented format support never send output_format'
    );

    $invalidUrl = false;
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'created' => 1,
            'data' => [['url' => 'ftp://example.test/out.png']],
        ]));
    };
    try {
        $imageModel->generateImageResult([$userMessage('A fish')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ResponseException $exception) {
        $invalidUrl = true;
    }
    check($invalidUrl, 'non-HTTP image URLs are rejected');

    $noImageData = false;
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(
            200,
            [],
            json_encode(['created' => 1, 'data' => []])
        );
    };
    try {
        $imageModel->generateImageResult([$userMessage('A fish')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ResponseException $exception) {
        $noImageData = true;
    }
    check($noImageData, 'responses without image data fail with a response error');

    $multipleMessages = false;
    try {
        $imageModel->generateImageResult([$userMessage('A'), $userMessage('B')]);
    } catch (\WordPress\AiClient\Common\Exception\InvalidArgumentException $exception) {
        $multipleMessages = true;
    }
    check($multipleMessages, 'image requests require exactly one message');

    $missingText = false;
    try {
        $imageModel->generateImageResult([
            new \WordPress\AiClient\Messages\DTO\Message(
                \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                [new \WordPress\AiClient\Messages\DTO\MessagePart(
                    new \WordPress\AiClient\Files\DTO\File('https://example.test/cat.png')
                )]
            ),
        ]);
    } catch (\WordPress\AiClient\Common\Exception\InvalidArgumentException $exception) {
        $missingText = true;
    }
    check($missingText, 'image requests require prompt text');

    $imageErrorMessage = '';
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(
            400,
            [],
            json_encode(['error' => ['message' => 'Unknown image model']])
        );
    };
    try {
        $imageModel->generateImageResult([$userMessage('A fish')]);
    } catch (\WordPress\AiClient\Providers\Http\Exception\ClientException $exception) {
        $imageErrorMessage = $exception->getMessage();
    }
    check(strpos($imageErrorMessage, 'Unknown image model') !== false, 'image API errors surface the provider message');

    // --- Image editing ------------------------------------------------------------------------
    $imageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputFileType' => 'inline',
        'customOptions' => [
            'mask' => 'https://example.test/mask.png',
            'quality' => 'high',
        ],
    ]));
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode([
            'created' => 2,
            'data' => [['b64_json' => base64_encode('edited-image')]],
        ]));
    };

    $editPrompt = [
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [
                new \WordPress\AiClient\Messages\DTO\MessagePart('Combine the references.'),
                new \WordPress\AiClient\Messages\DTO\MessagePart(
                    new \WordPress\AiClient\Files\DTO\File('https://example.test/a.png')
                ),
                new \WordPress\AiClient\Messages\DTO\MessagePart(
                    new \WordPress\AiClient\Files\DTO\File(base64_encode('fake-image'), 'image/png')
                ),
            ]
        ),
    ];
    $editResult = $imageModel->generateImageResult($editPrompt);
    $editRequest = $transporter->requests[count($transporter->requests) - 1];
    $editBody = json_decode((string) $editRequest->getBody(), true);

    check(
        $editRequest->getUri() === 'https://zenmux.ai/api/v1/images/edits',
        'image editing uses the edits endpoint'
    );
    check(
        ($editBody['images'][0]['image_url'] ?? null) === 'https://example.test/a.png',
        'remote references are sent as image URLs'
    );
    check(
        strpos($editBody['images'][1]['image_url'] ?? '', 'data:image/png;base64,') === 0,
        'local images are sent as data URIs'
    );
    check(!isset($editBody['response_format']), 'edit requests omit the format parameter');
    check(
        ($editBody['mask']['image_url'] ?? null) === 'https://example.test/mask.png',
        'the mask custom option becomes a mask reference'
    );
    check(($editBody['quality'] ?? null) === 'high', 'other custom options pass through to edits');
    check(count($editResult->getCandidates()) === 1, 'edited images are parsed into candidates');

    $manyImages = [];
    for ($index = 0; $index < 16; $index++) {
        $manyImages[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
            new \WordPress\AiClient\Files\DTO\File('https://example.test/img-' . $index . '.png')
        );
    }
    $imageModel->generateImageResult([
        new \WordPress\AiClient\Messages\DTO\Message(
            \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            array_merge([new \WordPress\AiClient\Messages\DTO\MessagePart('Collage.')], $manyImages)
        ),
    ]);
    $limitBody = json_decode(
        (string) $transporter->requests[count($transporter->requests) - 1]->getBody(),
        true
    );
    check(count($limitBody['images']) === 16, 'up to sixteen input images are accepted');

    $tooManyImages = false;
    $requestsBeforeFailure = count($transporter->requests);
    $seventeenImages = $manyImages;
    $seventeenImages[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
        new \WordPress\AiClient\Files\DTO\File('https://example.test/img-16.png')
    );
    try {
        $imageModel->generateImageResult([
            new \WordPress\AiClient\Messages\DTO\Message(
                \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                array_merge([new \WordPress\AiClient\Messages\DTO\MessagePart('Collage.')], $seventeenImages)
            ),
        ]);
    } catch (\WordPress\AiClient\Common\Exception\InvalidArgumentException $exception) {
        $tooManyImages = true;
    }
    check(
        $tooManyImages && count($transporter->requests) === $requestsBeforeFailure,
        'more than sixteen images are rejected before any request'
    );

    $nonEditableFailure = false;
    try {
        $plainImageModel->generateImageResult($editPrompt);
    } catch (\WordPress\AiClient\Common\Exception\InvalidArgumentException $exception) {
        $nonEditableFailure = strpos($exception->getMessage(), 'does not support image editing') !== false;
    }
    check($nonEditableFailure, 'editing fails for models without declared image input');

    // --- Malformed payload --------------------------------------------------------------------
    $malformed = new \ZenMux\AiProvider\Metadata\ZenMuxModelMetadataDirectory();
    $malformed->setHttpTransporter($transporter);
    $malformed->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('selfcheck-token')
    );
    $transporter->responder = static function (): \WordPress\AiClient\Providers\Http\DTO\Response {
        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode(['data' => []]));
    };

    $failed = false;
    try {
        $malformed->listModelMetadata();
    } catch (\WordPress\AiClient\Providers\Http\Exception\ResponseException $exception) {
        $failed = true;
    }
    check($failed, 'an empty model list fails with a response error');
}
