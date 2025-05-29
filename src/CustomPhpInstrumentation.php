<?php

declare(strict_types=1);

namespace NewfoldLabs\CustomPhp\Instrumentation;

use NewfoldLabs\CustomPhp\Instrumentation\Context\ConfigContext;
use Throwable;

/**
 * Class CustomPhpInstrumentation
 *
 * Initializes and manages the lifecycle of tracing and metrics instrumentation.
 * Sets up error handling, registers OpenTelemetry SDK, and gracefully shuts down on exceptions.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
class CustomPhpInstrumentation
{
    /**
     * @var array List of instrumentation classes to trigger lifecycle methods on.
     */
    private static array $classMap = [
        ConfigureInstrumentation::class,
        TraceInstrumentation::class,
        MetricsInstrumentation::class,
    ];

    /**
     * Calls a specified method on all instrumentation classes.
     *
     * @param string $function Method name to call.
     * @param mixed $args Arguments to pass to the method.
     * @return void
     */
    private static function trigger(string $function, mixed $args = []): void
    {
        array_map(function ($class) use ($function, $args) {
            if (method_exists($class, $function)) {
                if (!is_array($args)) {
                    $args = [$args];
                }
                call_user_func_array([$class, $function], $args);
            }
        }, self::$classMap);
    }

    /**
     * Bootstrap instrumentation registration.
     *
     * @return void
     */
    public static function register(): void
    {
        $config = ConfigContext::get();
        $serviceName = $config->get(Config::OTEL_SERVICE_NAME, false);

        if ($serviceName === false) {
            return;
        }

        self::trigger('register', $serviceName);
        self::init();
        self::start();
    }

    /**
     * Initializes error handling and shutdown logic.
     *
     * @return void
     */
    public static function init(): void
    {
        $config = ConfigContext::get();

        // Handle uncaught exceptions
        set_exception_handler(function (Throwable $e) use ($config): void {
            self::shutdown($e);

            $callback = $config->get('set_exception_handler', false);
            if ($callback) {
                $callback($e);
            }
        });

        // Register shutdown function
        register_shutdown_function([self::class, 'shutdown']);
    }

    /**
     * Starts tracing and metrics collection.
     *
     * @return void
     */
    public static function start(): void
    {
        self::trigger('start');
    }

    /**
     * Handles instrumentation shutdown logic.
     *
     * @param Throwable|null $e Optional exception passed during shutdown.
     * @return void
     */
    public static function shutdown(Throwable $e = null): void
    {
        self::trigger('shutdown', $e);
    }
}
