<?php

namespace NewfoldLabs\CustomPhp\Instrumentation\Context;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKey;
use NewfoldLabs\CustomPhp\Instrumentation\Config;

/**
 * Class ConfigContext
 *
 * Provides methods to attach and retrieve the Config instance
 * using OpenTelemetry's Context API.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation\Context
 * @author Mayur Saptal
 */
class ConfigContext
{
    /**
     * @var ContextKey Key used to store and retrieve the Config instance in the context.
     */
    private static ContextKey $key;

    /**
     * Initializes the context key and attaches a default Config instance.
     * Safe to call multiple times.
     *
     * @return void
     */
    public static function init(): void
    {
        self::$key = new ContextKey('otel-config');
        ConfigContext::attach(new Config());
    }

    /**
     * Attaches the given Config instance to the current context.
     *
     * @param Config $config Config instance to attach.
     * @return void
     */
    public static function attach(Config $config): void
    {
        if (!isset(self::$key)) {
            self::init();
        }

        $newContext = Context::getCurrent()->with(self::$key, $config);
        Context::storage()->attach($newContext);
    }

    /**
     * Retrieves the Config instance from the current context.
     *
     * @return Config|null Config instance if available, null otherwise.
     */
    public static function get(): ?Config
    {
        if (!isset(self::$key)) {
            self::init();
        }

        return Context::getCurrent()->get(self::$key);
    }
}
