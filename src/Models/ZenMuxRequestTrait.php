<?php

/**
 * Shared request construction for ZenMUX models.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

namespace ZenMux\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use ZenMux\AiProvider\Provider\ZenMuxProvider;
use ZenMux\AiProvider\Util\ZenMuxConfig;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

trait ZenMuxRequestTrait
{
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        $headers['User-Agent'] = ZenMuxConfig::getUserAgent();
        return new Request(
            $method,
            ZenMuxProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}
