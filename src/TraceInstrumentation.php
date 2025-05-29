<?php

namespace NewfoldLabs\CustomPhp\Instrumentation;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use function OpenTelemetry\Instrumentation\hook;
use NewfoldLabs\CustomPhp\Instrumentation\Interface\GenericInstrumentationInterface;
use NewfoldLabs\CustomPhp\Instrumentation\Context\ConfigContext;

use Throwable;

/**
 * Class TraceInstrumentation
 *
 * Implements OpenTelemetry tracing instrumentation for HTTP requests and application code.
 * It manages span creation, context propagation, error handling, and automatic function hooks.
 *
 * Usage:
 *  - Call {@see register()} once to initialize tracing instrumentation.
 *  - Call {@see start()} at the beginning of a request to start the root span.
 *  - Call {@see shutdown()} at the end of the request or script termination to close spans and flush data.
 *
 * Supports adding spans for exceptions, shutdown errors, and dynamically hooks into specified class methods.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
class TraceInstrumentation implements GenericInstrumentationInterface
{
    /**
     * Service name used for tracing.
     *
     * @var string|null
     */
    public static $serviceName = null;

    /**
     * CachedInstrumentation instance for tracer and metrics instruments.
     *
     * @var CachedInstrumentation|null
     */
    public static $instrumentation = null;

    /**
     * Unique global key to identify the root span, typically "HTTP METHOD URI".
     *
     * @var string|null
     */
    public static $globalKey = null;

    /**
     * Root span for the current request or operation.
     *
     * @var \OpenTelemetry\API\Trace\SpanInterface|null
     */
    public static $rootSpan = null;

    /**
     * Array to store current active spans keyed by hook identifier.
     *
     * @var array<string, \OpenTelemetry\API\Trace\SpanInterface>
     */
    public static array $currentSpans = [];

    /**
     * Registers the instrumentation by creating a CachedInstrumentation instance.
     *
     * @param string|null $serviceName Optional service name for tracing.
     * @return void
     */
    public static function register(string $serviceName = null): void
    {
        self::$serviceName = $serviceName;
        self::$instrumentation = new CachedInstrumentation($serviceName);
    }

    /**
     * Starts the root span for the current request and sets common HTTP attributes.
     *
     * Creates a CLIENT kind span representing the incoming HTTP request, sets
     * attributes such as URL, HTTP method, scheme, user agent, and activates the span.
     *
     * @return void
     */
    public static function start(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uriFull = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uriFull, PHP_URL_PATH) ?? '/';
        $port = $_SERVER['SERVER_PORT'] ?? null;
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        self::$globalKey = "HTTP $method $uri";

        $span = self::$instrumentation->tracer()
            ->spanBuilder(self::$globalKey)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $span
            ->setAttribute(TraceAttributes::SERVICE_NAME, self::$serviceName)
            ->setAttribute(TraceAttributes::HTTP_URL, $uri)
            ->setAttribute(TraceAttributes::URL_FULL, $uriFull)
            ->setAttribute(TraceAttributes::URL_SCHEME, $scheme)
            ->setAttribute(TraceAttributes::URL_PATH, $path)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $protocol)
            ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $userAgent)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, (int) $contentLength)
            ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $host)
            ->setAttribute(TraceAttributes::CLIENT_PORT, (int) $port)
            ->setAttribute(TraceAttributes::HTTP_METHOD, $method);

        $context = $span->storeInContext(Context::getCurrent());
        Context::storage()->attach($context);

        $span->activate();

        self::$rootSpan = $span;

        self::mapHooks();
    }

    /**
     * Shuts down tracing by ending the root span, recording any errors or exceptions,
     * and flushing the tracer provider.
     *
     * @param \Throwable|null $e Optional exception caught during the request lifecycle.
     * @return void
     */
    public static function shutdown(Throwable $e = null): void
    {
        if ($e !== null) {
            self::addExceptionSpan($e);
        }

        if (self::$rootSpan) {
            $lastError = error_get_last();
            if ($lastError) {
                self::addShutdownErrorSpan($lastError);
                self::$rootSpan->setStatus(StatusCode::STATUS_ERROR, print_r($lastError, true));
            } else {
                self::$rootSpan->setStatus(StatusCode::STATUS_OK, 'Request completed');
            }

            self::$rootSpan->end();
        }

        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider !== null) {
            $tracerProvider->shutdown();
        }
    }

    /**
     * Adds a span for any fatal error detected during script shutdown.
     *
     * The span includes the file and line number of the error, the error message,
     * and a custom event describing the shutdown error.
     *
     * @param array $lastError The last error information from error_get_last().
     * @return void
     */
    public static function addShutdownErrorSpan(array $lastError): void
    {
        $parentContext = Context::getCurrent();

        $span = self::$instrumentation->tracer()->spanBuilder(self::$globalKey . " Shutdown Error Span")
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setParent($parentContext)
            ->startSpan();

        $span->activate();

        $span->setAttribute(TraceAttributes::CODE_FILEPATH, $lastError['file'] ?? 'unknown')
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lastError['line'] ?? -1)
            ->setStatus(StatusCode::STATUS_ERROR, $lastError['message'] ?? 'Unknown error');

        $span->addEvent('Script Shutdown Error', [
            'message' => $lastError['message'] ?? 'N/A',
            'file' => $lastError['file'] ?? 'N/A',
            'line' => $lastError['line'] ?? 0,
        ]);

        $span->end();
    }

    /**
     * Adds spans for each frame in an exception's backtrace.
     *
     * Each span is created as an INTERNAL kind child span with attributes for
     * function, class, file, and line number.
     *
     * @param \Throwable $e Exception object to trace.
     * @return void
     */
    public static function addExceptionSpan(Throwable $e): void
    {
        $trace = $e->getTrace();

        foreach ($trace as $index => $frame) {
            $function = $frame['function'] ?? '(unknown)';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $spanName = self::$globalKey . " Backtrace Frame: {$class}{$type}{$function}";

            $span = self::$instrumentation->tracer()
                ->spanBuilder($spanName)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent(Context::getCurrent())
                ->startSpan();

            $span->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                ->setAttribute(TraceAttributes::CODE_FILEPATH, $frame['file'] ?? 'unknown')
                ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $frame['line'] ?? -1)
                ->setAttribute('backtrace.index', $index);

            if (isset($frame['args'])) {
                $span->setAttribute('backtrace.args_count', count($frame['args']));
            }

            $span->end();
        }
    }

    /**
     * Maps hooks on methods of configured classes to create spans for function entry and exit.
     *
     * Uses OpenTelemetry instrumentation hooks to automatically trace method calls,
     * setting span attributes from function parameters and handling exceptions.
     *
     * The hook config can come from:
     *  - 'auto_scan_class' : array of class names whose methods will be hooked.
     *  - 'class_function_map' : specific mapping of class methods to hook with optional pre/post callbacks.
     *
     * @return void
     */
    public static function mapHooks(): void
    {
        $hookClasses = [];
        $genericHooks = [];

        $trackConfig = ConfigContext::get()->get('trace', false);

        if ($trackConfig && isset($trackConfig['auto_scan_class'])) {
            $hookClasses = $trackConfig['auto_scan_class'];
        }

        if ($trackConfig && isset($trackConfig['class_function_map'])) {
            $genericHooks = $trackConfig['class_function_map'];
        }

        $prepareMap = function (string $class) use (&$genericHooks) {
            if (!class_exists($class)) {
                return;
            }

            foreach (get_class_methods($class) as $method) {
                $key = "$class->$method";

                if (isset($genericHooks[$key])) {
                    continue;
                }

                $genericHooks[$key] = [
                    'class' => $class,
                    'function' => $method,
                    'pre' => '',
                    'post' => ''
                ];
            }
        };

        foreach ($hookClasses as $class) {
            $prepareMap($class);
        }

        if (empty($genericHooks)) {
            return;
        }

        foreach ($genericHooks as $key => ['class' => $class, 'function' => $function, 'pre' => $pre, 'post' => $post]) {
            if (is_numeric($key)) {
                $key = $class . '->' . $function;
            }

            $pre = is_callable($pre) ? $pre : static function ($client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($key) {
                $parentContext = Context::getCurrent();

                $span = TraceInstrumentation::$instrumentation->tracer()
                    ->spanBuilder($key)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setParent($parentContext)
                    ->startSpan();

                $span->activate();
                TraceInstrumentation::$currentSpans[$key] = $span;

                $span->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('code.function.params', json_encode($params));
            };

            $post = is_callable($post) ? $post : static function ($client, array $params, $response, ?Throwable $exception) use ($key) {
                if (!isset(TraceInstrumentation::$currentSpans[$key])) {
                    return;
                }

                $span = TraceInstrumentation::$currentSpans[$key];

                if ($exception) {
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } else {
                    $span->setStatus(StatusCode::STATUS_OK, 'Request completed');
                }

                $span->end();
                unset(TraceInstrumentation::$currentSpans[$key]);
            };

            hook(
                $class,
                $function,
                pre: $pre,
                post: $post
            );
        }
    }
}
