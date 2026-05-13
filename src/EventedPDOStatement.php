<?php

declare(strict_types=1);

namespace SQLiteEvents;

final class EventedPDOStatement extends \PDOStatement
{
    protected function __construct(private readonly EventedPDO $pdo)
    {
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $executed = null === $params
            ? parent::execute()
            : parent::execute($params);

        if ($executed) {
            $this->pdo->dispatchAfterSuccessfulStatement();
        }

        return $executed;
    }
}
