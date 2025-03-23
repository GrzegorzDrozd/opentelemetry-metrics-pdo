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