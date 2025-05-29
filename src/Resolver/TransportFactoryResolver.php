<?php

namespace NewfoldLabs\CustomPhp\Instrumentation\Resolver;

use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use NewfoldLabs\CustomPhp\Instrumentation\Context\ConfigContext;

/**
 * Class TransportFactoryResolver
 *
 * Resolves the appropriate transport factories for traces and metrics
 * based on the configured mode: 'file' or 'otlp'.
 *
 * - File mode writes data to local NDJSON-formatted files.
 * - OTLP mode sends data using OTLP over HTTP with Protobuf encoding.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation\Resolver
 * @author Mayur Saptal
 */
class TransportFactoryResolver
{
    /**
     * Resolves the transport for trace data.
     *
     * @param string $mode Either 'file' or 'otlp'.
     * @return TransportInterface
     */
    public static function resolveTrace(string $mode): TransportInterface
    {
        $config = ConfigContext::get();

        if ($mode === 'file') {
            $path = $config->get($config::OTEL_TRACE_FILE_PATH, __DIR__ . '/trace.jsonl');
            $file = fopen($path, 'a');
            return (new StreamTransportFactory())->create($file, ContentTypes::NDJSON);
        }

        $endpoint = $config->get('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        return (new OtlpHttpTransportFactory())->create($endpoint, ContentTypes::PROTOBUF);
    }

    /**
     * Resolves the transport for metric data.
     *
     * @param string $mode Either 'file' or 'otlp'.
     * @return TransportInterface
     */
    public static function resolveMetric(string $mode): TransportInterface
    {
        $config = ConfigContext::get();

        if ($mode === 'file') {
            $path = $config->get($config::OTEL_METRIC_FILE_PATH, __DIR__ . '/metric.jsonl');
            $file = fopen($path, 'a');
            return (new StreamTransportFactory())->create($file, ContentTypes::NDJSON);
        }

        $endpoint = $config->get('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT');
        return (new OtlpHttpTransportFactory())->create($endpoint, ContentTypes::PROTOBUF);
    }
}
