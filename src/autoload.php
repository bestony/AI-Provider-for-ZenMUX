<?php

/**
 * Minimal PSR-4 autoloader for the plugin namespace.
 *
 * @package ZenMux\AiProvider
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZenMux\\AiProvider\\';
    $length = strlen($prefix);
    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, $length)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
