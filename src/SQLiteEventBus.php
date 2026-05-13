<?php

declare(strict_types=1);

namespace SQLiteEvents;

use PDO;
use Throwable;

final class SQLiteEventBus
{
    public const EVENT_TABLE = '__sqlite_events_log';

    private ListenerRegistry $listeners;
    private TriggerInstaller $installer;

    public function __construct(private readonly PDO $pdo)
    {
        $this->listeners = new ListenerRegistry();
        $this->installer = new TriggerInstaller($pdo, self::EVENT_TABLE);
    }

    public function install(): void
    {
        $this->installer->install();
    }

    /**
     * @param callable(ChangeEvent): void $listener
     */
    public function listen(string $table, ChangeAction $action, callable $listener): void
    {
        $this->listeners->add($this->listenerName($table, $action), $listener);
    }

    public function dispatchPending(int $limit = 100): int
    {
        $this->ensureInstalled();

        $select = $this->pdo->prepare(
            sprintf(
                'SELECT "id", "table_name", "action", "row_id", "primary_key", "payload", "changed_at"
                 FROM %s
                 ORDER BY "id"
                 LIMIT :limit',
                self::quoteIdentifier(self::EVENT_TABLE)
            )
        );
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->execute();

        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $delete = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE "id" = :id', self::quoteIdentifier(self::EVENT_TABLE))
        );

        $dispatched = 0;

        foreach ($rows as $row) {
            $event = ChangeEvent::fromDatabaseRow($row);

            try {
                $this->listeners->dispatch($event);
            } catch (Throwable $exception) {
                throw $exception;
            }

            $delete->execute([':id' => $event->id]);
            $dispatched++;
        }

        return $dispatched;
    }

    public function pendingCount(): int
    {
        $this->ensureInstalled();

        return (int) $this->pdo
            ->query(sprintf('SELECT COUNT(*) FROM %s', self::quoteIdentifier(self::EVENT_TABLE)))
            ->fetchColumn();
    }

    private function ensureInstalled(): void
    {
        $this->installer->ensureEventTable();
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function listenerName(string $table, ChangeAction $action): string
    {
        if ($table === '*' && $action === ChangeAction::Any) {
            return '*';
        }

        if ($table === '*') {
            return $action->value;
        }

        return $table . '.' . $action->value;
    }
}
