<?php

declare(strict_types=1);

use OpenTelemetry\Metrics\PDO\ConfigurationHelper;
use OpenTelemetry\Metrics\PDO\PDOMetrics;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && !ConfigurationHelper::areMetricsEnabled('pdo')) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Metrics for PDO', E_USER_WARNING);

    return;
}

PDOMetrics::register();
