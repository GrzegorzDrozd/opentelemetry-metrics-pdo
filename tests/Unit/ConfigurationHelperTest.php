<?php

namespace OpenTelemetry\Tests\Metrics\PDO\Unit;

use OpenTelemetry\Metrics\PDO\ConfigurationHelper;
use PHPUnit\Framework\TestCase;

class ConfigurationHelperTest extends TestCase
{

    public function testAreMetricsEnabled(): void
    {
        $this->assertTrue(ConfigurationHelper::areMetricsEnabled('test'));
    }

    public function testAllMetricsDisabled(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=*');
        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('test'));
    }

    public function testAllMetricsDisabledAndSomeOtherNamed(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=*,test');
        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('test'));
    }

    public function testInList(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=test,test2');

        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('test'));
        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('test2'));

        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('TEST'));
        $this->assertFalse(ConfigurationHelper::areMetricsEnabled('Test2'));

        $this->assertTrue(ConfigurationHelper::areMetricsEnabled('test3'));
    }
}
