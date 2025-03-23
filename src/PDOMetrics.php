<?php

declare(strict_types=1);

namespace OpenTelemetry\Metrics\PDO;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\TraceAttributes;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

class PDOMetrics
{
    // Semantic convection attributes:
    protected const DB_CLIENT_OPERATION_DURATION = 'db.client.operation.duration';
    protected const DB_CLIENT_RESPONSE_RETURNED_ROWS = 'db.client.response.returned_rows';
    protected const DB_CLIENT_CONNECTION_COUNT = 'db.client.connection.count';
    protected const DB_CLIENT_CONNECTION_CREATE_TIME = 'db.client.connection.create_time';

    // custom attributes
    protected const DB_CLIENT_CONNECTION_ERROR_COUNT = 'db.client.connection.error.count';
    protected const DB_CLIENT_OPERATION_ERROR_COUNT = 'db.client.operation.error.count';

    protected static MeterInterface $meter;
    protected static PDOMetricsTracker $tracker;

    public static function register(): void
    {
        self::$tracker = new PDOMetricsTracker(
            self::isContextTrackingEnabled(),
            self::isStatementTrackingEnabled(),
            self::isRowsReturnedMetricEnabled(),
        );

        hook(
            PDO::class,
            '__construct',
            pre: self::startTracker(...),
            post: function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception): void {
                $duration = self::$tracker->stop($pdo);

                self::$tracker->trackConnection($pdo);
                $context = self::getContext(self::$tracker->isTrackContext());
                $attributes = self::$tracker->addAttributes($pdo, DsnParser::parseDsn($params[0]));

                $attributes = self::handleException(self::DB_CLIENT_CONNECTION_ERROR_COUNT, $exception, $attributes, $context);
                self::recordDuration(self::DB_CLIENT_CONNECTION_CREATE_TIME, $duration, $attributes, $context);

                if (null === $exception) {
                    self::changeUpDownCounter(self::DB_CLIENT_CONNECTION_COUNT, 1, $attributes, $context);
                }
            },
        );

        hook(PDO::class, 'query', self::startTracker(...), self::handlePDODBOperation(...));
        hook(PDO::class, 'exec', self::startTracker(...), self::handlePDODBOperation(...));
        hook(PDOStatement::class, 'execute', self::startTracker(...), self::handlePDODBOperation(...));

        hook(
            PDO::class,
            'prepare',
            post: static function (PDO $pdo, array $params, mixed $return) {
                self::$tracker->mapStatementToConnection($return, $pdo);
            },
        );

        register_shutdown_function(self::cleanUpConnections(...));
    }

    /**
     * @return MeterInterface
     */
    public static function getMeter(): MeterInterface
    {
        return Globals::meterProvider()->getMeter('io.opentelemetry.metrics.pdo');
    }

    protected static function startTracker(PDO|PDOStatement $pdo): void
    {
        self::$tracker->start($pdo);
    }

    protected static function handlePDODBOperation(PDO|PDOStatement $pdo, ?array $params, PDOStatement|int|bool|null $return, ?Throwable $exception): void
    {
        $duration = self::$tracker->stop($pdo);

        $context = self::getContext(self::$tracker->isTrackContext());

        $statement = null;
        if ($pdo instanceof PDOStatement) {
            $statement = $pdo->queryString;
            $pdo = self::$tracker->getConnectionFromStatement($pdo);
        } elseif (!empty($params[0])) {
            $statement = $params[0];
        }

        $attributes = [];
        if (null !== $pdo) {
            $attributes = self::$tracker->getAttributes($pdo);
        }

        if (null !== $statement && self::$tracker->isTrackStatements()) {
            $attributes[TraceAttributes::DB_QUERY_TEXT] = $statement;
        }

        $attributes = self::handleException(self::DB_CLIENT_OPERATION_ERROR_COUNT, $exception, $attributes, $context);
        self::recordDuration(self::DB_CLIENT_OPERATION_DURATION, $duration, $attributes, $context);

        if (!self::$tracker->isTrackRowsReturned()) {
            return;
        }

        if ($return instanceof PDOStatement) {
            self::recordNumber(self::DB_CLIENT_RESPONSE_RETURNED_ROWS, $return->rowCount(), $attributes, $context);
        } elseif (is_int($return)) {
            self::recordNumber(self::DB_CLIENT_RESPONSE_RETURNED_ROWS, $return, $attributes, $context);
        }
    }

    protected static function handleException(string $errorName, ?Throwable $exception, array $attributes = [], ?ContextInterface $context = null): array
    {
        if (null === $exception) {
            return $attributes;
        }
        $exceptionAttributes = [
            TraceAttributes::EXCEPTION_TYPE => get_class($exception),
            TraceAttributes::EXCEPTION_MESSAGE => $exception->getMessage(),
            TraceAttributes::CODE_LINE_NUMBER =>$exception->getLine(),
            TraceAttributes::CODE_FILEPATH =>$exception->getFile(),
        ];

        if ($exception instanceof PDOException) {
            $exceptionAttributes[TraceAttributes::ERROR_TYPE] = $exception->getCode();
        }

        $attributes = [...$attributes, ...$exceptionAttributes];
        self::increaseCounter($errorName, 1, $attributes, $context);

        return $attributes;
    }

    protected static function cleanUpConnections(): void
    {
        $context = self::getContext(self::$tracker->isTrackContext());
        // pdo does not have __destruct, so we need to "disconnect" manually
        foreach (self::$tracker->getConnections() as $pdo => $ref) {
            if (!($pdo instanceof PDO)) {
                continue;
            }
            $attributes = self::$tracker->getAttributes($pdo);
            self::changeUpDownCounter(self::DB_CLIENT_CONNECTION_COUNT, -1, $attributes, $context);
        }
    }

    protected static function isContextTrackingEnabled(): bool
    {
        return self::readBoolConfig(
            'OTEL_PHP_METRICS_PDO_CONTEXT_TRACKING',
            'otel.instrumentation.metrics.pdo.context_tracking',
        );
    }

    protected static function isStatementTrackingEnabled(): bool
    {
        return self::readBoolConfig(
            'OTEL_PHP_METRICS_PDO_STATEMENT_TRACKING',
            'otel.instrumentation.metrics.pdo.statement_tracking',
        );
    }

    protected static function isRowsReturnedMetricEnabled(): bool
    {
        return self::readBoolConfig(
            'OTEL_PHP_METRICS_PDO_SEND_ROWS_RETURNED',
            'otel.instrumentation.metrics.pdo.send_rows_returned',
        );
    }

    protected static function readBoolConfig($envName, $configName, bool $default = true): bool
    {
        /** @noinspection ClassConstantCanBeUsedInspection */
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            return Configuration::getBoolean($envName, $default);
        }

        $value = get_cfg_var($configName);
        return match (true) {
            is_bool($value) => $value,
            (is_string($value) && $default === true) => strtolower($value) === 'true',
            (is_string($value) && $default === false) => strtolower($value) === 'false',
            default => $default,
        };
    }

    protected static function getContext(bool $trackContext): ?ContextInterface
    {
        return $trackContext ? Context::getCurrent() : null;
    }

    protected static function increaseCounter(string $name, int $by = 1, ?array $attributes = [], ContextInterface|false|null $context = null): void
    {
        self::getMeter()->createCounter($name)->add($by, $attributes, $context);
    }

    protected static function changeUpDownCounter(string $name, int $by = 1, ?array $attributes = [], ContextInterface|false|null $context = null): void
    {
        self::getMeter()->createUpDownCounter($name)->add($by, $attributes, $context);
    }

    protected static function recordDuration(string $name, float $duration, ?array $attributes = [], ContextInterface|false|null $context = null): void
    {
        self::recordNumber($name, $duration, $attributes, $context, 'ms');
    }

    protected static function recordNumber(string $name, float $duration, ?array $attributes = [], ContextInterface|false|null $context = null, string $unit = '1'): void
    {
        self::getMeter()->createHistogram($name, $unit)->record($duration, $attributes, $context);
    }
}
