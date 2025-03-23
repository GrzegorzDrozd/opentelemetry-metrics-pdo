<?php

declare(strict_types=1);

namespace Integration;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\NoopEventLoggerProvider;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Metrics\PDO\PDOMetrics;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\AllExemplarFilter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricFactory\StreamFactory;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\StalenessHandler\NoopStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\TraceAttributes;
use PDO;
use PHPUnit\Framework\TestCase;

class PDOMetricsTest extends TestCase
{
    public function test_it_works(): void
    {

        $consoleExporter = new ConsoleMetricExporter();
        $metricReader = new ExportingReader($consoleExporter);

        $meterProvider = new MeterProvider(
            null,
            ResourceInfoFactory::emptyResource(),
            Clock::getDefault(),
            Attributes::factory(),
            new InstrumentationScopeFactory(Attributes::factory()),
            [$metricReader],
            new CriteriaViewRegistry(),
            new AllExemplarFilter(),
            new NoopStalenessHandlerFactory(),
            new StreamFactory(),
            Configurator::meter(),
        );

        $tracerProvider = new NoopTracerProvider();
        $loggerProvider = new NoopLoggerProvider();
        $eventLoggerProvider = new NoopEventLoggerProvider();
        $textMapPropagator = new NoopTextMapPropagator();
        $g = new Globals(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            $eventLoggerProvider,
            $textMapPropagator
        );

        $ref = new \ReflectionClass(Globals::class);
        $ref->setStaticPropertyValue('globals', $g);

        putenv('OTEL_PHP_METRICS_PDO_STATEMENT_TRACKING=true');

        ob_start();
        $pdo1 = new PDO('sqlite::memory:');
        $pdo1->query('CREATE TABLE example (id INTEGER PRIMARY KEY);');
        $statement = 'select * from sqlite_master';
        $stmt = $pdo1->prepare($statement);
        $stmt->execute();

        $pdo2 = new PDO('sqlite::memory:');
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $pdo2->query('bad statement');
        } catch (\PDOException) {}

        $m = Globals::meterProvider();
        if ($m instanceof MeterProvider) {
            $m->forceFlush();

            $metricsReflection = new \ReflectionClass(PDOMetrics::class);
            $cleanUpMethod = $metricsReflection->getMethod('cleanUpConnections');
            $cleanUpMethod->invoke(null);
        }

        $content = ob_get_clean();
        $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $metrics = $result['scope']['metrics'];

        $this->assertCount(5, $metrics);

        foreach ($metrics as $row) {
            $this->assertEquals('sqlite', $row['data']['dataPoints'][0]['attributes'][TraceAttributes::DB_SYSTEM_NAME]);
            $this->assertEquals(':memory:', $row['data']['dataPoints'][0]['attributes'][TraceAttributes::SERVER_ADDRESS]);
        }

        $m = $this->getMetricsByType($metrics, 'db.client.response.returned_rows');
        $this->assertEquals(1, $m['data']['dataPoints'][0]['count']);

        $m = $this->getMetricsByType($metrics, 'db.client.operation.error.count');
        $this->assertEquals('bad statement', $m['data']['dataPoints'][0]['attributes'][TraceAttributes::DB_QUERY_TEXT]);
    }

    protected function getMetricsByType($metrics, $type): array
    {
        foreach ($metrics as $metric) {
            if ($metric['name'] === $type) {
                return $metric;
            }
        }
        return [];
    }
}
