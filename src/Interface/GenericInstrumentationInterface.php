<?php

declare(strict_types=1);

namespace NewfoldLabs\CustomPhp\Instrumentation\Interface;

use Throwable;

/**
 * Interface GenericInstrumentationInterface
 *
 * Defines the contract for implementing instrumentation logic,
 * including registration, startup, and shutdown handling.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
interface GenericInstrumentationInterface
{
    /**
     * Registers the instrumentation for the given service.
     *
     * @param string|null $serviceName Optional service name for registration.
     * @return void
     */
    public static function register(string $serviceName = null): void;

    /**
     * Starts the instrumentation process, including tracing and metrics collection.
     *
     * @return void
     */
    public static function start(): void;

    /**
     * Shuts down the instrumentation and handles any cleanup logic.
     *
     * @param Throwable|null $e Optional exception to handle during shutdown.
     * @return void
     */
    public static function shutdown(Throwable $e = null): void;
}
