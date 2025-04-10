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
    public function setUp(): void
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
    }

    public function test_it_works(): void
    {
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

    public function test_it_works_with_attributes(): void
    {
        ob_start();
        PDOMetrics::addAttribute('global', 'attribute_for_all_connections');
        $pdo1 = new PDO('sqlite:file:memdb1?mode=memory');
        PDOMetrics::addAttribute('local', 'attribute_for_this_connection', $pdo1);
        $pdo1->query('CREATE TABLE example (id INTEGER PRIMARY KEY);');
        $statement = 'select * from sqlite_master';
        $stmt1 = $pdo1->prepare($statement);
        $stmt1->execute();
        PDOMetrics::addAttribute('global_from_here', 'for_all_statements');
        PDOMetrics::addAttributes(['more'=>'than', 'one'=>'attribute'], $stmt1);

        $stmt2 = $pdo1->prepare('select * from sqlite_master limit 1');
        PDOMetrics::addAttribute('local_statement', 'only_for_one_statement', $stmt2);
        $stmt2->execute();

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
        foreach($metrics as $metric) {
            foreach($metric['data']['dataPoints'] as $dataPoint) {
                $this->assertArrayHasKey('global', $dataPoint['attributes']);
            }
        }

        $metricsForConnection1 = $this->getMetricsForConnection($metrics, 'file:memdb1?mode=memory');
        $connectionAttributesCount = 0;
        $statementAttributesCount = 0;
        $globalFromPointCount = 0;
        foreach($metricsForConnection1 as $dataPoint) {
            $connectionAttributesCount += array_key_exists('local', $dataPoint['attributes']) ? 1 : 0;
            $statementAttributesCount += array_key_exists('local_statement', $dataPoint['attributes']) ? 1 : 0;
            $globalFromPointCount += array_key_exists('global_from_here', $dataPoint['attributes']) ? 1 : 0;
        }
        $this->assertEquals(4, $connectionAttributesCount);
        $this->assertEquals(1, $statementAttributesCount);
        $this->assertEquals(1, $globalFromPointCount);
        $metricsForConnection2 = $this->getMetricsForConnection($metrics, ':memory:');

        $connectionAttributesCount = 0;
        $statementAttributesCount = 0;
        $globalFromPointCount = 0;
        foreach ($metricsForConnection2 as $dataPoint) {
            $connectionAttributesCount += array_key_exists('local', $dataPoint['attributes']) ? 1 : 0;
            $statementAttributesCount += array_key_exists('local_statement', $dataPoint['attributes']) ? 1 : 0;
            $globalFromPointCount += array_key_exists('global_from_here', $dataPoint['attributes']) ? 1 : 0;
        }
        $this->assertEquals(0, $connectionAttributesCount);
        $this->assertEquals(0, $statementAttributesCount);
        $this->assertEquals(4, $globalFromPointCount);

        // only 2 because only those are "global"
        $attributes = PDOMetrics::getAttributes();
        $this->assertArrayHasKey('global', $attributes);
        $this->assertArrayHasKey('global_from_here', $attributes);

        $attributesForPdo1 = PDOMetrics::getAttributes($pdo1);
        $this->assertArrayHasKey('global', $attributesForPdo1);
        $this->assertArrayHasKey('global_from_here', $attributesForPdo1);
        $this->assertArrayHasKey('local', $attributesForPdo1);

        $attributesForPdo2 = PDOMetrics::getAttributes($pdo2);
        $this->assertArrayHasKey('global', $attributesForPdo2);
        $this->assertArrayHasKey('global_from_here', $attributesForPdo2);
        $this->assertArrayNotHasKey('local', $attributesForPdo2);

        $attributesForStmt1 = PDOMetrics::getAttributes($stmt1);
        $this->assertArrayHasKey('global', $attributesForStmt1);
        $this->assertArrayHasKey('global_from_here', $attributesForStmt1);
        $this->assertArrayHasKey('more', $attributesForStmt1);
        $this->assertArrayHasKey('one', $attributesForStmt1);
        $this->assertArrayNotHasKey('local_statement', $attributesForStmt1);
        $this->assertArrayNotHasKey('local', $attributesForStmt1);

        PDOMetrics::removeAttribute('one', $stmt1);
        $attributesForStmt1 = PDOMetrics::getAttributes($stmt1);
        $this->assertArrayHasKey('global', $attributesForStmt1);
        $this->assertArrayHasKey('more', $attributesForStmt1);
        $this->assertSame(null, $attributesForStmt1['one']);



        $attributesForStmt2 = PDOMetrics::getAttributes($stmt2);
        $this->assertArrayHasKey('global', $attributesForStmt2);
        $this->assertArrayHasKey('global_from_here', $attributesForStmt2);
        $this->assertArrayHasKey('local_statement', $attributesForStmt2);
        $this->assertArrayNotHasKey('local', $attributesForStmt2);
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

    protected function getMetricsForConnection($metrics, $serverAddress): array
    {
        $ret = [];
        foreach($metrics as $metric) {
            foreach($metric['data']['dataPoints'] as $dataPoint) {
                if ($dataPoint['attributes'][TraceAttributes::SERVER_ADDRESS] === $serverAddress) {
                    $ret[] = $dataPoint;
                }
            }
        }
        return $ret;
    }
}
