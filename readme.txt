=== AI Provider for ZenMUX ===
Contributors: bestony
Tags: ai, zenmux, llm, artificial-intelligence, connector
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ZenMUX provider for the WordPress AI Client.

== Description ==

This plugin connects WordPress AI features to ZenMUX, an OpenAI-compatible AI aggregation
service. It registers a ZenMUX provider with the WordPress AI Client and supports:

* Text generation and chat history, including tools and structured JSON output.
* Vision input for models that accept images.
* Image generation for image models.
* Image editing (refinement) when the prompt carries one or more reference images.

The model catalog is read from ZenMUX's `GET /models` endpoint, so models ZenMUX adds or
removes appear automatically.

== Setup ==

1. Activate the plugin.
2. Open **Settings > Connectors** and save your ZenMUX API key. You can create one at
   https://zenmux.ai/platform/pay-as-you-go.
3. Optional: configure the provider with environment variables or PHP constants:

* `ZENMUX_BASE_URL` - API base URL. Default `https://zenmux.ai/api/v1`.
* `ZENMUX_DEFAULT_MODEL` - preferred text and vision model. Default `openai/gpt-5`.
* `ZENMUX_IMAGE_MODEL` - preferred image model. Default `openai/gpt-image-2`.
* `ZENMUX_REQUEST_TIMEOUT` - request timeout in seconds. Default `120`.
* `ZENMUX_CONNECT_TIMEOUT` - connection timeout in seconds. Default `10`.
* `ZENMUX_API_KEY` - the API key, as an alternative to the Connectors screen.

== External Services ==

This plugin connects to ZenMUX, a service provided by NexaMind Singapore Pte. Ltd. The
connection is required to generate AI output. The plugin sends your prompts to ZenMUX through
its API and displays the generated result in WordPress. No other external service is used.

Data is sent only when an AI request is made while a ZenMUX model is selected or preferred,
and only after the site administrator has configured a ZenMUX API key. Each request sends:

* The prompt, conversation messages, and any system instruction.
* Attached image data, or a reference to it, when the request contains an image.
* Tool and function declarations, and the JSON schema used for structured output, when the request uses them.
* The configured model ID and the generation settings for the request, such as token limits, temperature, stop sequences, image size, and quality.
* The ZenMUX API key stored in **Settings > Connectors**, sent as the Authorization header.

Requests go to the ZenMUX API base URL, `https://zenmux.ai/api/v1` by default. The plugin
contacts these endpoints:

* `GET /models` - lists the available models.
* `POST /chat/completions` - text and vision generation.
* `POST /images/generations` - image generation.
* `POST /images/edits` - image editing.

Nothing is sent to the plugin author or to any other service.

ZenMUX documentation: https://zenmux.ai/docs/
ZenMUX terms of service: https://zenmux.ai/docs/terms-of-service.html
ZenMUX privacy policy: https://zenmux.ai/docs/privacy.html

== Frequently Asked Questions ==

= Where do I get a ZenMUX API key? =

Create one at https://zenmux.ai/platform/pay-as-you-go and save it in **Settings > Connectors**.

= Which models can I use? =

The plugin lists the models reported by ZenMUX's `GET /models` endpoint and classifies them by
their input and output modalities. Text models support chat, tools, and vision where the model
reports image input. Image models support generation and, when they accept image input, image
editing.

= Does the plugin work without the Connectors screen? =

Yes. Providing `ZENMUX_API_KEY` as an environment variable or PHP constant also connects the
provider.

== Changelog ==

= 1.0.0 =
* Initial release: text and vision generation, image generation, image editing, and a model
  catalog read from ZenMUX.
