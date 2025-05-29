<?php

declare(strict_types=1);

use NewfoldLabs\CustomPhp\Instrumentation\CustomPhpInstrumentation;

/**
 * Bootstrap File
 *
 * Initializes OpenTelemetry auto-instrumentation for WordPress using custom PHP logic.
 * Ensures the required OpenTelemetry extension is loaded before proceeding.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation
 * @author Mayur Saptal
 */

// Ensure the OpenTelemetry extension is loaded
if (!extension_loaded('opentelemetry')) {
    trigger_error(
        'The opentelemetry extension must be loaded in order to autoload the OpenTelemetry WordPress auto-instrumentation',
        E_USER_WARNING
    );
    return;
}


// Register custom PHP instrumentation
CustomPhpInstrumentation::register();
