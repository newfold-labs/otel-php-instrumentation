<?php

namespace NewfoldLabs\CustomPhp\Instrumentation;

/**
 * Class Config
 *
 * Provides access to OpenTelemetry-related configuration variables.
 * Values are retrieved from environment variables or an optional config file.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
class Config
{
    // Trace & Metric mode
    public const OTEL_MODE = 'OTEL_MODE';

    // OTLP endpoints
    public const OTEL_EXPORTER_OTLP_TRACES_ENDPOINT = 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT';
    public const OTEL_EXPORTER_OTLP_METRICS_ENDPOINT = 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT';

    // File paths for file-based exports
    public const OTEL_TRACE_FILE_PATH = 'OTEL_TRACE_FILE_PATH';
    public const OTEL_METRIC_FILE_PATH = 'OTEL_METRIC_FILE_PATH';

    // Application name
    public const OTEL_SERVICE_NAME = 'OTEL_SERVICE_NAME';

    // Optional config file path
    public const OTEL_CONFIG_FILE_PATH = 'OTEL_CONFIG_FILE_PATH';

    /**
     * @var array Configuration values loaded from a PHP config file.
     */
    public static array $fileConfig = [];

    /**
     * Config constructor.
     * Loads configuration values from a file if specified.
     */
    public function __construct()
    {
        $this->setConfigFromFile();
    }

    /**
     * Retrieves a configuration value from the file or environment.
     *
     * @param string $key The configuration key.
     * @param mixed $default Default value if key is not found.
     * @return mixed Configuration value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$fileConfig[$key])) {
            return self::$fileConfig[$key];
        }

        return getenv($key) ?: $default;
    }

    /**
     * Loads configuration values from the file specified by OTEL_CONFIG_FILE_PATH.
     *
     * @return void
     */
    public function setConfigFromFile(): void
    {
        $configFile = $this->get(self::OTEL_CONFIG_FILE_PATH, false);

        if ($configFile && file_exists($configFile)) {
            self::$fileConfig = require_once($configFile);
        }
    }
}
