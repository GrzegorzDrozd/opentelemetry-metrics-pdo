<?php

declare(strict_types=1);

namespace OpenTelemetry\Metrics\PDO;

use InvalidArgumentException;
use OpenTelemetry\SemConv\TraceAttributes;

class DsnParser
{
    public static function parseDsn(string $dsn): array
    {
        if (!str_contains($dsn, ':')) {
            throw new InvalidArgumentException('Invalid DSN format');
        }

        /**
         * There is a check for ':' existence above, so there will be always two elements.
         * @psalm-suppress PossiblyUndefinedArrayOffset
         */
        [$prefix, $dsn] = explode(':', $dsn, 2);
        $ret = [TraceAttributes::DB_SYSTEM_NAME => $prefix];

        if ($prefix === 'sqlite') {
            $ret[TraceAttributes::SERVER_ADDRESS] = $dsn;

            return $ret;
        }

        foreach (explode(';', $dsn) as $parameter) {
            if (!str_contains($parameter, '=')) {
                continue;
            }
            /**
             * There is a check for ':' existence above, so there will be always two elements.
             * @psalm-suppress PossiblyUndefinedArrayOffset
             */
            [$key, $value] = explode('=', $parameter, 2);

            $mappedKey = match ($key) {
                'host', 'unix_socket' => TraceAttributes::SERVER_ADDRESS,
                'port' => TraceAttributes::SERVER_PORT,
                'dbname' => TraceAttributes::DB_NAMESPACE,
                'user' => TraceAttributes::DB_USER,
                default => $key,
            };
            $ret[$mappedKey] = $value;
        }

        return $ret;
    }
}
