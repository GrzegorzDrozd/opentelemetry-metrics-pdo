<?php

namespace OpenTelemetry\Tests\Metrics\PDO\Unit;

use InvalidArgumentException;
use OpenTelemetry\Metrics\PDO\DsnParser;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DsnParser class's parseDsn method.
 */
class DsnParserTest extends TestCase
{
    public function testParseDsnWithValidMysqlDsn(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=testdb;user=test';
        $expected = [
            TraceAttributes::DB_SYSTEM_NAME => 'mysql',
            TraceAttributes::SERVER_ADDRESS => '127.0.0.1',
            TraceAttributes::SERVER_PORT => '3306',
            TraceAttributes::DB_NAMESPACE => 'testdb',
            TraceAttributes::DB_USER => 'test',
        ];

        $this->assertEquals($expected, DsnParser::parseDsn($dsn));
    }

    public function testParseDsnWithValidSqliteDsn(): void
    {
        $dsn = 'sqlite:/path/to/database.sqlite';
        $expected = [
            TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
            TraceAttributes::SERVER_ADDRESS => '/path/to/database.sqlite',
        ];

        $this->assertEquals($expected, DsnParser::parseDsn($dsn));
    }

    public function testParseDsnWithInvalidFormatThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN format');

        DsnParser::parseDsn('invalid-dsn-format');
    }

    public function testParseDsnWithUnknownParameters(): void
    {
        $dsn = 'mysql:host=127.0.0.1;unknown=value';
        $expected = [
            TraceAttributes::DB_SYSTEM_NAME => 'mysql',
            TraceAttributes::SERVER_ADDRESS => '127.0.0.1',
            'unknown' => 'value',
        ];

        $this->assertEquals($expected, DsnParser::parseDsn($dsn));
    }

    public function testParseDsnWithNoHostParameter(): void
    {
        $dsn = 'mysql:port=3306;dbname=testdb';
        $expected = [
            TraceAttributes::DB_SYSTEM_NAME => 'mysql',
            TraceAttributes::SERVER_PORT => '3306',
            TraceAttributes::DB_NAMESPACE => 'testdb',
        ];

        $this->assertEquals($expected, DsnParser::parseDsn($dsn));
    }
}