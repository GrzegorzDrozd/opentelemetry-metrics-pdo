<?php

namespace OpenTelemetry\Tests\Metrics\PDO\Unit;

use OpenTelemetry\Metrics\PDO\PDOMetricsTracker;
use PDO;
use PHPUnit\Framework\TestCase;

class PDOMetricsTrackerTest extends TestCase
{
    public function testStartAndStop(): void
    {
        $tracker = new PDOMetricsTracker(true, true, true);

        $pdo = new PDO('sqlite::memory:');

        $tracker->start($pdo);
        usleep(100);
        $duration = $tracker->stop($pdo);
        $this->assertGreaterThan(0, $duration);
    }

    public function testAttributes(): void
    {
        $tracker = new PDOMetricsTracker(true, true, true);

        $pdo = new PDO('sqlite::memory:');

        $this->assertSame(['foo'=>'bar'], $tracker->addAttributes($pdo, ['foo'=>'bar']));
        $this->assertSame(['foo'=>'bar', 'baz'=>'zzz'], $tracker->addAttributes($pdo, ['baz'=>'zzz']));

        $this->assertSame(['foo'=>'bar', 'baz'=>'zzz'], $tracker->getAttributes($pdo));
    }

    public function testStatementSpecificAttributes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->prepare('select 1');
        $tracker = new PDOMetricsTracker(true, true, true);
        $tracker->addAttributes($stmt, ['foo'=>'bar']);
        $this->assertSame(['foo'=>'bar'], $tracker->getAttributes($stmt));
    }

    public function testGlobalAttributes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->prepare('select 1');
        $tracker = new PDOMetricsTracker(true, true, true);
        $tracker->addGlobalAttributes( ['foo'=>'bar']);
        $this->assertSame(['foo'=>'bar'], $tracker->getAttributes($stmt));
        $this->assertSame(['foo'=>'bar'], $tracker->getGlobalAttributes());

    }

    public function testTrackConnection(): void
    {
        $tracker = new PDOMetricsTracker(true, true, true);

        $pdo1 = new PDO('sqlite::memory:');
        $pdo2 = new PDO('sqlite::memory:');
        $tracker->trackConnection($pdo1);
        $tracker->trackConnection($pdo2);
        $this->assertCount(2, $tracker->getConnections());
    }

    public function testMapStatementToConnection(): void
    {
        $tracker = new PDOMetricsTracker(true, true, true);

        $pdo1 = new PDO('sqlite::memory:');
        $pdo2 = new PDO('sqlite::memory:');

        $stmt1 = $pdo1->prepare('select 1');
        $stmt2 = $pdo2->prepare('select 1');

        $tracker->mapStatementToConnection($stmt1, $pdo1);
        $tracker->mapStatementToConnection($stmt2, $pdo2);

        $this->assertEquals($pdo1, $tracker->getConnectionFromStatement($stmt1));
        $this->assertEquals($pdo2, $tracker->getConnectionFromStatement($stmt2));
    }


    public function testMapStatementToConnectionWithUnknownStatement(): void
    {
        $tracker = new PDOMetricsTracker(true, true, true);
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->prepare('select 1');
        $this->assertNull($tracker->getConnectionFromStatement($stmt));
    }
}
