<?php

namespace OpenTelemetry\Metrics\PDO;

use OpenTelemetry\SDK\Common\Configuration\Configuration;

class ConfigurationHelper
{
    public static function areMetricsEnabled(string $group): bool
    {
        /** @noinspection ClassConstantCanBeUsedInspection */
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            $list = Configuration::getList('OTEL_PHP_DISABLED_METRICS', []);
        } else {
            $value = get_cfg_var('otel.instrumentation.disabled.metrics');
            $list = match (true) {
                is_string($value) => [$value],
                is_array($value) => $value,
                default => []
            };
        }

        if (empty($list)) {
            return true;
        }
        if (in_array('*', $list, true)) {
            return false;
        }

        $list = array_map('strtolower', array_map('trim', $list));

        $group = strtolower(trim($group));
        return !in_array($group, $list, true);
    }
}
