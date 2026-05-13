<?php

declare(strict_types=1);

namespace SQLiteEvents;

final class EventedPDO extends \PDO
{
    private SQLiteEventBus $events;
    private int $autoDispatchSuppression = 0;

    /**
     * @param array<int|string, mixed>|null $options
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        bool $autoInstall = false,
    ) {
        parent::__construct($dsn, $username, $password, $options ?? []);

        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [EventedPDOStatement::class, [$this]]);
        $this->events = new SQLiteEventBus($this);

        if ($autoInstall) {
            $this->installEvents();
        }
    }

    /**
     * @param array<int|string, mixed>|null $options
     */
    public static function connect(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        bool $autoInstall = true,
    ): self {
        return new self($dsn, $username, $password, $options, $autoInstall);
    }

    public function events(): SQLiteEventBus
    {
        return $this->events;
    }

    /**
     * Register any PHP callable.
     *
     * @param callable(ChangeEvent): void $listener
     */
    public function listen(string $table, ChangeAction $action, callable $listener): void
    {
        $this->events->listen($table, $action, $listener);
    }

    public function installEvents(): void
    {
        $this->withoutAutoDispatch(fn (): null => $this->events->install());
    }

    public function dispatchPendingEvents(int $limit = 100): int
    {
        return $this->withoutAutoDispatch(
            fn (): int => $this->events->dispatchPending($limit)
        );
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);

        if (false !== $result) {
            $this->dispatchAfterSuccessfulStatement();
        }

        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $statement = match (true) {
            null === $fetchMode => parent::query($query),
            [] === $fetchModeArgs => parent::query($query, $fetchMode),
            default => parent::query($query, $fetchMode, ...$fetchModeArgs),
        };

        if (false !== $statement) {
            $this->dispatchAfterSuccessfulStatement();
        }

        return $statement;
    }

    public function commit(): bool
    {
        $committed = parent::commit();

        if ($committed) {
            $this->dispatchAfterSuccessfulStatement();
        }

        return $committed;
    }

    public function rollBack(): bool
    {
        return parent::rollBack();
    }

    public function dispatchAfterSuccessfulStatement(): void
    {
        if ($this->autoDispatchSuppression > 0 || $this->inTransaction()) {
            return;
        }

        $this->dispatchPendingEvents();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    private function withoutAutoDispatch(\Closure $callback): mixed
    {
        ++$this->autoDispatchSuppression;

        try {
            return $callback();
        } finally {
            --$this->autoDispatchSuppression;
        }
    }
}
