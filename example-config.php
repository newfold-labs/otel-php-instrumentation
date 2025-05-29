<?php

use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\API\Trace\SpanKind;
use NewfoldLabs\CustomPhp\Instrumentation\TraceInstrumentation;

/**
 * Configuration array for tracing, metrics, and exception handling.
 *
 * @return array Configuration settings for instrumentation.
 */
return [

    /**
     * Exception handler callback.
     * Logs exceptions with a unique identifier and redirects to an error page.
     *
     * @param \Throwable $e The caught exception.
     * @return void
     */
    'set_exception_handler' => function ($e) {
        $logMessage = $e->getMessage();

        // Use UNIQUE_ID from server or fallback to hash of session ID
        $hashKey = filter_input(INPUT_SERVER, 'UNIQUE_ID') ?? hash('sha256', session_id());

        @error_log(
            var_export([$hashKey, date(DATE_RFC7231), $logMessage], true) . PHP_EOL,
            3,
            '/var/log/error.log'
        );

        // Redirect user to friendly error page with unique error ID
        header('Location: /error.html?id=' . $hashKey);
        exit;
    },

    /**
     * Tracing configuration.
     */
    'trace' => [

        /**
         * List of classes to automatically scan for instrumentation.
         * These class names should be autoloadable and case-sensitive.
         *
         * @var string[]
         */
        'auto_scan_class' => [
            'ABC',
            'PQR',
            'XYZ'
        ],

        /**
         * Map specific class methods for manual instrumentation hooks.
         * Each entry allows specifying pre- and post-invocation callbacks.
         *
         * @var array[]
         */
        'class_function_map' => [
            [
                'class' => 'ABC',
                'function' => '__construct',

                /**
                 * Pre-invocation callback.
                 * Starts a new trace span before the constructor executes.
                 *
                 * @param mixed $client The instance under construction.
                 * @param array $params Function parameters.
                 * @param string $class Class name.
                 * @param string $function Function name.
                 * @param string|null $filename Source file name.
                 * @param int|null $lineno Line number in source file.
                 * @return void
                 */
                'pre' => static function ($client, array $params, string $class, string $function, ?string $filename, ?int $lineno) {

                    $key = $class . '->' . $function;
                    $parentContext = Context::getCurrent();

                    // Start a new span representing this constructor call
                    $span = TraceInstrumentation::$instrumentation->tracer()
                        ->spanBuilder($key)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setParent($parentContext)
                        ->startSpan();

                    // Activate the span to make it current
                    $span->activate();

                    // Add useful attributes for debugging and analysis
                    $span->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                    // Store the span so it can be ended in the post hook
                    TraceInstrumentation::$currentSpans[$key] = $span;
                },

                /**
                 * Post-invocation callback.
                 * Ends the span after the constructor executes.
                 *
                 * @param mixed $client The instance under construction.
                 * @param array $params Function parameters.
                 * @param string $class Class name.
                 * @param string $function Function name.
                 * @return void
                 */
                'post' => static function ($client, array $params, string $class, string $function) {
                    $key = $class . '->' . $function;
                    if (isset(TraceInstrumentation::$currentSpans[$key])) {
                        TraceInstrumentation::$currentSpans[$key]->end();
                        unset(TraceInstrumentation::$currentSpans[$key]);
                    }
                }
            ],
        ],
    ],

    /**
     * Metrics configuration.
     * Defines URIs for health check endpoints where system metrics are gathered.
     *
     * NOTE: Make sure this key matches the expected key in the SystemMetrics class.
     *
     * @var string[]
     */
    'metrics' => [
        'healthcheck_uri' => [
            '/ping.php',
        ],
    ],
];
