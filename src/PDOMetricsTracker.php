<?php

declare(strict_types=1);

namespace OpenTelemetry\Metrics\PDO;

use PDO;
use PDOStatement;
use WeakMap;
use WeakReference;

class PDOMetricsTracker
{
    /**
     * @var WeakMap<PDO, WeakReference>|WeakMap<object, mixed>
     */
    protected WeakMap $connections;

    /**
     * @var WeakMap<PDO|PDOStatement, float>|WeakMap<object, mixed>
     */
    protected WeakMap $timers;

    /**
     * @var WeakMap<PDO, array>|WeakMap<object, mixed>
     */
    protected WeakMap $attributes;

    /**
     * @var array<string, mixed>
     */
    protected array $globalAttributes = [];
    
    /**
     * @var WeakMap<PDOStatement, WeakReference>|WeakMap<object, mixed>
     */
    protected WeakMap $statementMapToPdoMap;

    public function __construct(
        protected bool $trackContext,
        protected bool $trackStatements,
        protected bool $trackRowsReturned,
    ) {
        $this->connections = new WeakMap();
        $this->timers = new WeakMap();
        $this->attributes = new WeakMap();
        $this->statementMapToPdoMap = new WeakMap();
    }

    public function start(PDO|PDOStatement $pdo): void
    {
        $this->timers[$pdo] = microtime(true);
    }

    public function stop(PDO|PDOStatement $pdo): float
    {
        // this could happen when the register ran twice!
        if ($this->timers->offsetExists($pdo) === false) {
            return 0.0;
        }
        $duration = microtime(true) - $this->timers[$pdo];
        unset($this->timers[$pdo]);

        return $duration;
    }

    public function addAttributes(PDO|PDOStatement $pdo, array $attributes = []): array
    {
        $this->attributes[$pdo] ??= [];
        if (!empty($attributes)) {
            /** @psalm-suppress InvalidOperand */
            $this->attributes[$pdo] = [...$this->attributes[$pdo], ...$attributes];
        }

        return [...$this->attributes[$pdo], ...$this->globalAttributes];
    }

    public function addGlobalAttributes(array $attributes = []): array
    {
        return $this->globalAttributes = [...$this->globalAttributes, ...$attributes];
    }

    public function getGlobalAttributes(): array
    {
        return $this->globalAttributes;
    }

    public function getAttributes(PDO|PDOStatement $pdo): array
    {
        return [...($this->attributes[$pdo] ?? []), ...$this->globalAttributes];
    }

    public function trackConnection(PDO $pdo): void
    {
        $this->connections[$pdo] = WeakReference::create($pdo);
    }

    /**
     * @return WeakMap<PDO, WeakReference>|WeakMap<object, mixed>
     */
    public function getConnections(): WeakMap
    {
        return $this->connections;
    }

    public function mapStatementToConnection(PDOStatement $statement, PDO $pdo): void
    {
        $this->statementMapToPdoMap[$statement] = WeakReference::create($pdo);
    }

    public function getConnectionFromStatement(PDOStatement $statement): ?PDO
    {
        return ($this->statementMapToPdoMap[$statement] ?? null)?->get();
    }

    public function isTrackContext(): bool
    {
        return $this->trackContext;
    }

    public function isTrackStatements(): bool
    {
        return $this->trackStatements;
    }

    public function isTrackRowsReturned(): bool
    {
        return $this->trackRowsReturned;
    }
}
