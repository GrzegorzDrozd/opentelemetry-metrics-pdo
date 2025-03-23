<?php
putenv('OTEL_METRICS_EXPORTER=console');
putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_METRICS_EXEMPLAR_FILTER=all');
putenv('OTEL_SERVICE_NAME=pdo-metrics-test');
putenv('OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta');

require_once 'vendor/autoload.php';