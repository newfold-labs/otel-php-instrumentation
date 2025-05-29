<?php

namespace NewfoldLabs\CustomPhp\Instrumentation;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\MetricExporter as OtlpMetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use NewfoldLabs\CustomPhp\Instrumentation\Context\ConfigContext;
use NewfoldLabs\CustomPhp\Instrumentation\Resolver\TransportFactoryResolver;

/**
 * Class ConfigureInstrumentation
 *
 * Configures and registers the OpenTelemetry SDK for tracing and metrics.
 * The configuration mode ('file' or 'otlp') is determined via the OTEL_MODE environment variable.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */
class ConfigureInstrumentation
{
    /**
     * Registers OpenTelemetry instrumentation by initializing and configuring
     * the tracer and meter providers with the appropriate exporters.
     *
     * @return void
     */
    public static function register(): void
    {
        $config = ConfigContext::get();
        $mode = strtolower($config->get(Config::OTEL_MODE, 'file'));

        // Resolve appropriate trace and metric transport based on mode
        $traceTransport = TransportFactoryResolver::resolveTrace($mode);
        $metricTransport = TransportFactoryResolver::resolveMetric($mode);

        // Setup Tracer Provider with exporter
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(new SpanExporter($traceTransport))
        );

        // Setup Meter Provider with exporter
        $meterProvider = (new MeterProviderBuilder())
            ->addReader(new ExportingReader(new OtlpMetricExporter($metricTransport)))
            ->build();

        // Register the global OpenTelemetry SDK
        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($meterProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }
}
