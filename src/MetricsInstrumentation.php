<?php

namespace NewfoldLabs\CustomPhp\Instrumentation;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use NewfoldLabs\CustomPhp\Instrumentation\Interface\GenericInstrumentationInterface;
use Throwable;

/**
 * Class MetricsInstrumentation
 *
 * Handles registration, collection, and shutdown of metrics for HTTP requests and system-level metrics.
 *
 * This class uses OpenTelemetry to track metrics related to HTTP requests such as request counts,
 * methods, paths, response codes, client IPs, and other relevant HTTP metadata.
 * It also registers system-level metrics via a separate SystemMetrics handler.
 *
 * Usage:
 *  - Call {@see register()} once at startup to initialize instrumentation.
 *  - Call {@see start()} for each HTTP request to record metrics.
 *  - Call {@see shutdown()} during application shutdown to clean up the meter provider.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
class MetricsInstrumentation implements GenericInstrumentationInterface
{
    /**
     * @var string|null Service name used for labeling metrics.
     */
    public static ?string $serviceName = null;

    /**
     * @var CachedInstrumentation|null Cached instrumentation instance used to create and cache metrics instruments.
     */
    public static ?CachedInstrumentation $instrumentation = null;

    /**
     * Registers the instrumentation and system-level metrics.
     *
     * @param string|null $serviceName Service name to use in metrics labels.
     * @return void
     */
    public static function register(string $serviceName = null): void
    {
        self::$serviceName = $serviceName;
        self::$instrumentation = new CachedInstrumentation($serviceName);
    }

    /**
     * Starts collecting HTTP request metrics.
     *
     * Extracts request information from $_SERVER and increments a counter metric
     * `http_requests_total` with appropriate attributes.
     *
     * @return void
     */
    public static function start(): void
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $port = $_SERVER['SERVER_PORT'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $counter = self::$instrumentation->meter()->createCounter('http_requests_total');
        $counter->add(1, [
            'job' => self::$serviceName,
            'job.name' => self::$serviceName,
            'http.method' => $method,
            'http.path' => $path,
            'http.status_code' => http_response_code(),
            'http.host' => $host,
            'http.user_agent' => $userAgent,
            'http.scheme' => $scheme,
            'http.port' => (int) $port,
            'http.protocol' => $protocol,
            'http.request_content_length' => (int) $contentLength,
            'client.ip' => $clientIp,
        ]);

        SystemMetrics::register(self::$instrumentation->meter(), self::$serviceName);
    }

    /**
     * Gracefully shuts down the meter provider.
     *
     * Ensures that any buffered or pending metrics data is flushed and resources are released.
     *
     * @param Throwable|null $e Optional exception for context during shutdown.
     * @return void
     */
    public static function shutdown(Throwable $e = null): void
    {
        $meterProvider = Globals::meterProvider();
        if ($meterProvider !== null) {
            $meterProvider->shutdown();
        }
    }
}
