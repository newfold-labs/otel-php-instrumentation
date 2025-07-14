# OpenTelemetry PHP Instrumentation
**Supports Laravel, WordPress, and Custom PHP Frameworks**

This package provides a plug-and-play instrumentation layer for collecting traces, metrics, and error data in PHP applications using OpenTelemetry.

It is designed to work seamlessly with:
- Laravel
- WordPress
- Custom PHP applications or frameworks

---

## 🚀 Installation



**Step 1: Add the custom repository**
```json
"repositories": [
  {
    "type": "git",
    "url": "https://github.com/newfold-labs/otel-php-instrumentation"
  }
]
```

**Step 2: Require the package**
```bash
composer require newfold-labs/otel-php-instrumentation
```

---

## ⚙️ Environment Variables

Set these variables in your `.env` or server environment:

| Variable                                | Description                                      | Required   | Example                  |
|-----------------------------------------|--------------------------------------------------|------------|--------------------------|
| `OTEL_MODE`                             | Export mode (`file` or `otlp`)                   | ✅         | `file`                   |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`    | OTLP HTTP endpoint for traces (OTLP mode)        | ✅ (otlp)  | `http://localhost:4318`  |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`   | OTLP HTTP endpoint for metrics (OTLP mode)       | ✅ (otlp)  | `http://localhost:4318`  |
| `OTEL_TRACE_FILE_PATH`                  | File path for traces (file mode)                 | ✅ (file)  | `/tmp/trace.json`        |
| `OTEL_METRIC_FILE_PATH`                 | File path for metrics (file mode)                | ✅ (file)  | `/tmp/metric.json`       |
| `OTEL_SERVICE_NAME`                     | Logical service name                             | ✅         | `my-app`                 |
| `OTEL_CONFIG_FILE_PATH`                 | Path to optional config file                     | Optional   | `/path/to/config.php`    |

---

## 🛠️ Configuration File

Define a configuration PHP file and set its path using `OTEL_CONFIG_FILE_PATH`. Here's an example:

```php
<?php

use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\API\Trace\SpanKind;
use NewfoldLabs\CustomPhp\Instrumentation\TraceInstrumentation;

return [

    'set_exception_handler' => function ($e) {
        $logMessage = $e->getMessage();
        $hashKey = filter_input(INPUT_SERVER, 'UNIQUE_ID') ?? hash('sha256', session_id());

        @error_log(
            var_export([$hashKey, date(DATE_RFC7231), $logMessage], true) . PHP_EOL,
            3,
            '/var/log/error.log'
        );

        header('Location: /error.html?id=' . $hashKey);
        exit;
    },

    'trace' => [
        'auto_scan_class' => [
            'ABC',
            'PQR',
            'XYZ',
        ],

        'class_function_map' => [
            [
                'class' => 'ABC',
                'function' => '__construct',
                'pre' => static function ($client, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                    $key = $class . '->' . $function;
                    $parentContext = Context::getCurrent();

                    $span = TraceInstrumentation::$instrumentation->tracer()
                        ->spanBuilder($key)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setParent($parentContext)
                        ->startSpan();

                    $span->activate();

                    $span->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                    TraceInstrumentation::$currentSpans[$key] = $span;
                },
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

    'metrics' => [
        'healthcheck_uri' => [
            '/ping.php',
        ],
    ],
];
```

---

## ✅ Features

- Auto and manual method instrumentation  
- Export traces and metrics to OTLP endpoint or local file  
- Exception logging with unique error codes and redirect support  
- System health endpoint monitoring (e.g. `/ping.php`)

---

## 🧩 Framework Compatibility

This package is framework-agnostic and can be integrated into:

- Laravel (via service providers or middleware)  
- WordPress (via plugins or hooks)  
- Any custom PHP stack (via bootstrapping or configuration loader)

---

## 🧪 Example Manual Instrumentation

You can extend the config file to manually trace methods:

```php
[
    'class' => 'MyService',
    'function' => 'handleRequest',
    'pre' => function (...) { /* Start span */ },
    'post' => function (...) { /* End span */ },
]
```

---

## 🤝 Contributing

Pull requests and feature suggestions are welcome. Just fork the repo and open a PR.

---

## 📄 License

MIT © Newfold Labs
