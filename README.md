# AI Provider for ZenMUX

ZenMUX as an OpenAI-compatible provider for the [WordPress AI Client](https://github.com/WordPress/php-ai-client).

This is an independent third-party integration. It is not affiliated with or endorsed by ZenMUX.

## What it does

- Registers the `zenmux` provider with the WordPress AI Client, so WordPress AI features can
  select ZenMUX models.
- Builds the model catalog from ZenMUX's `GET /models` endpoint, classifying each model by its
  reported input and output modalities.
- Supports text generation and chat history, tools and function calls, structured JSON output,
  and vision input for models that accept images.
- Supports image generation through `POST /images/generations` and image editing through
  `POST /images/edits` for models that accept reference images.
- Never reads the API key itself: credential presence and the key are owned by the AI Client
  registry (`Settings -> Connectors` or `ZENMUX_API_KEY`).

The provider adapts OpenAI's protocol to ZenMUX's specifics: `maxTokens` is sent as
`max_completion_tokens`, JSON schemas are wrapped as `{name, schema}`, and reasoning text from
the `reasoning` field is exposed as thought parts.

## Requirements

- WordPress 7.0 or later, with the bundled WordPress AI Client.
- PHP 7.4 or later.

## Install

Copy this directory to `wp-content/plugins/ai-provider-for-zenmux/`, activate the plugin, then
open **Settings -> Connectors** and enter a ZenMUX API key
(create one at https://zenmux.ai/platform/pay-as-you-go).

## Configuration

The provider ID is `zenmux`. Configuration is environment/constant based:

| Name | Default | Purpose |
| --- | --- | --- |
| `ZENMUX_API_KEY` | - | API key alternative to the Connectors screen |
| `ZENMUX_BASE_URL` | `https://zenmux.ai/api/v1` | API base URL |
| `ZENMUX_DEFAULT_MODEL` | `openai/gpt-5` | Preferred text and vision model |
| `ZENMUX_IMAGE_MODEL` | `openai/gpt-image-2` | Preferred image model |
| `ZENMUX_REQUEST_TIMEOUT` | `120` | Request timeout in seconds |
| `ZENMUX_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds |

## External services

The plugin sends requests to ZenMUX (`https://zenmux.ai` by default):

- `GET /api/v1/models` lists the model catalog.
- `POST /api/v1/chat/completions` performs text and vision generation.
- `POST /api/v1/images/generations` performs image generation.
- `POST /api/v1/images/edits` performs image editing.

Requests carry the prompt, conversation messages, system instructions, attached image data,
tool and schema definitions when used, and the generation settings. The API key is sent as a
bearer token. Nothing is sent to the plugin author or to any other service.

- ZenMUX documentation: https://zenmux.ai/docs/
- ZenMUX terms of service: https://zenmux.ai/docs/terms-of-service.html
- ZenMUX privacy policy: https://zenmux.ai/docs/privacy.html

## Development

Run the WordPress-free self-check:

```sh
php scripts/selfcheck.php
```

Add `--sdk=<path>` pointing at a `php-ai-client` checkout, or at the copy bundled with
WordPress (`wp-includes/php-ai-client`), to exercise catalog parsing, request construction,
response parsing, and registry credential checks:

```sh
php scripts/selfcheck.php --sdk=/path/to/wp-includes/php-ai-client
```
