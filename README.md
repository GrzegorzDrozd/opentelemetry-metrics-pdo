# OpenTelemetry metrics for PDO

Please read https://opentelemetry.io/docs/concepts/signals/metrics/ for basic information about metrics.
.

## Overview
Metric collection is installed via Composer, and metrics are sent using default collector for selected PDO operations.

## Configuration

You can disable the extension using [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_METRICS=pdo
```
               
## Recommended OTEL Metrics settings:

```shell
OTEL_METRICS_EXEMPLAR_FILTER=all
```
Use this setting to send all data like spanId, traceId, and attributes with metric value.

```shell
OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta
```
To make sure that all metrics are sent and not pre-processed before it is best to send this to `delta`. Otherwise, metrics like `db.client.connection.count` will always send 0 instead of +1 when connection is established and -1 when connection is closed. This metric is specially useful in long-running code that runs from cli like console commands or cron jobs. But it also contains both timestamps — one for connection increase, and one for connection number decrease. Combined with flushing from time to time (more often than on the script end) will show *current* number of established db connections. To force flush, you can use this in one of the console events or somewhere in your processing loop:  

```php
<?php
$m = Globals::meterProvider();
// meterProvider returns MeterProviderInterface interface but actual implementation has forceFlush method  
if ($m instanceof \OpenTelemetry\SDK\Metrics\MeterProvider) {
    $m->forceFlush();
}
```

### Adding Custom Attributes

You can add custom attributes to your PDO metrics either globally or per specific PDO/PDOStatement instance. The following methods are available:

```php
// Add a single attribute
PDOMetrics::addAttribute('key', 'value'); // globally
PDOMetrics::addAttribute('key', 'value', $pdoInstance); // for specific PDO instance
PDOMetrics::addAttribute('key', 'value', $pdoStmt); // for specific Statement instance

// Add multiple attributes at once
PDOMetrics::addAttributes(['key1' => 'value1', 'key2' => 'value2']); // globally
PDOMetrics::addAttributes(['key1' => 'value1', 'key2' => 'value2'], $pdoInstance); // for specific PDO instance
PDOMetrics::addAttributes(['key1' => 'value1', 'key2' => 'value2'], $pdoStmt); // for specific Statement instance

// Remove an attribute
PDOMetrics::removeAttribute('key'); // globally
PDOMetrics::removeAttribute('key', $pdoInstance); // for specific PDO instance
PDOMetrics::removeAttribute('key', $pdoStmt); // for specific Statement instance

// Get current attributes
$attributes = PDOMetrics::getAttributes(); // get global attributes
$attributes = PDOMetrics::getAttributes($pdoInstance); // get attributes for specific PDO instance
$attributes = PDOMetrics::getAttributes($pdoStmt); // get attributes for specific Statement instance
```
These attributes will be included with all metrics generated for the relevant scope (global or instance-specific). Instance-specific attributes are combined with global attributes when metrics are generated.
          
This is useful for tracking information that might be lost from spans due to sampling on traces.

### Additional configuration options

#### Disable context tracking

If you don't want to add traceId and spanId to metrics, you can disable it using the following env variable (it is enabled by default):
```shell
OTEL_PHP_METRICS_PDO_CONTEXT_TRACKING=false
```

#### Disable adding statements to metrics

If you don't want to add a statement to metrics, you can disable it using the following env variable (it is enabled by default):
```shell
OTEL_PHP_METRICS_PDO_STATEMENT_TRACKING=false
```

#### Disable tracking number of returned rows

If you don't want to send the number of returned rows (as a separate, histogram metric), you can disable it using the following env variable (it is enabled by default):
```shell
OTEL_PHP_METRICS_PDO_SEND_ROWS_RETURNED=false
```