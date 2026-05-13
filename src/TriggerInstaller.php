<?php

declare(strict_types=1);

namespace SQLiteEvents;

use PDO;

final class TriggerInstaller
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $eventTable,
    ) {
    }

    public function install(): void
    {
        $this->ensureEventTable();

        foreach ($this->tables() as $table) {
            $this->installForTable($table);
        }
    }

    public function ensureEventTable(): void
    {
        $this->pdo->exec(
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                    "table_name" TEXT NOT NULL,
                    "action" TEXT NOT NULL CHECK ("action" IN (\'insert\', \'update\', \'delete\')),
                    "row_id" TEXT NULL,
                    "primary_key" TEXT NULL,
                    "payload" TEXT NOT NULL,
                    "changed_at" TEXT NOT NULL DEFAULT (strftime(\'%%Y-%%m-%%dT%%H:%%M:%%fZ\', \'now\'))
                )',
                SQLiteEventBus::quoteIdentifier($this->eventTable)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tableList = $this->pdo->query('PRAGMA table_list');

        if ($tableList !== false) {
            $tables = [];

            foreach ($tableList->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string) $row['name'];

                if (
                    ($row['schema'] ?? null) === 'main'
                    && ($row['type'] ?? null) === 'table'
                    && $name !== 'sqlite_schema'
                    && $name !== $this->eventTable
                    && !str_starts_with($name, 'sqlite_')
                ) {
                    $tables[] = $name;
                }
            }

            sort($tables);

            return $tables;
        }

        $statement = $this->pdo->prepare(
            'SELECT "name"
             FROM "sqlite_schema"
             WHERE "type" = \'table\'
               AND "name" NOT LIKE \'sqlite_%\'
               AND "name" != :eventTable
             ORDER BY "name"'
        );
        $statement->execute([':eventTable' => $this->eventTable]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function installForTable(string $table): void
    {
        $columns = $this->columns($table);

        if ($columns === []) {
            return;
        }

        $withoutRowId = $this->isWithoutRowId($table);
        $primaryKeyColumns = array_values(array_filter(
            $columns,
            static fn (array $column): bool => $column['pk'] > 0
        ));
        usort(
            $primaryKeyColumns,
            static fn (array $left, array $right): int => $left['pk'] <=> $right['pk']
        );

        foreach (['insert', 'update', 'delete'] as $action) {
            $trigger = $this->triggerName($table, $action);
            $this->pdo->exec(sprintf('DROP TRIGGER IF EXISTS %s', SQLiteEventBus::quoteIdentifier($trigger)));
            $this->pdo->exec($this->createTriggerSql($table, $action, $columns, $primaryKeyColumns, $withoutRowId));
        }
    }

    /**
     * @return list<array{name: string, pk: int}>
     */
    private function columns(string $table): array
    {
        $statement = $this->pdo->query(sprintf('PRAGMA table_info(%s)', SQLiteEventBus::quoteIdentifier($table)));
        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => (string) $row['name'],
                'pk' => (int) $row['pk'],
            ];
        }

        return $columns;
    }

    private function isWithoutRowId(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT "sql" FROM "sqlite_schema" WHERE "type" = \'table\' AND "name" = :table');
        $statement->execute([':table' => $table]);

        $sql = (string) $statement->fetchColumn();

        return str_contains(strtoupper($sql), 'WITHOUT ROWID');
    }

    /**
     * @param list<array{name: string, pk: int}> $columns
     * @param list<array{name: string, pk: int}> $primaryKeyColumns
     */
    private function createTriggerSql(
        string $table,
        string $action,
        array $columns,
        array $primaryKeyColumns,
        bool $withoutRowId,
    ): string {
        $trigger = SQLiteEventBus::quoteIdentifier($this->triggerName($table, $action));
        $quotedTable = SQLiteEventBus::quoteIdentifier($table);
        $rowAlias = $action === 'delete' ? 'OLD' : 'NEW';
        $rowIdExpression = $withoutRowId ? 'NULL' : sprintf('CAST(%s."rowid" AS TEXT)', $rowAlias);
        $oldPayload = $action === 'insert' ? 'NULL' : $this->jsonObjectExpression('OLD', $columns);
        $newPayload = $action === 'delete' ? 'NULL' : $this->jsonObjectExpression('NEW', $columns);

        return sprintf(
            'CREATE TRIGGER %s
             AFTER %s ON %s
             BEGIN
                 INSERT INTO %s ("table_name", "action", "row_id", "primary_key", "payload")
                 VALUES (%s, %s, %s, %s, json_object(\'old\', %s, \'new\', %s));
             END',
            $trigger,
            strtoupper($action),
            $quotedTable,
            SQLiteEventBus::quoteIdentifier($this->eventTable),
            $this->quoteLiteral($table),
            $this->quoteLiteral($action),
            $rowIdExpression,
            $this->jsonObjectExpression($rowAlias, $primaryKeyColumns),
            $oldPayload,
            $newPayload
        );
    }

    /**
     * @param list<array{name: string, pk: int}> $columns
     */
    private function jsonObjectExpression(string $rowAlias, array $columns): string
    {
        if ($columns === []) {
            return 'json_object()';
        }

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = $this->quoteLiteral($column['name']);
            $parts[] = $this->jsonValueExpression($rowAlias, $column['name']);
        }

        return 'json_object(' . implode(', ', $parts) . ')';
    }

    private function jsonValueExpression(string $rowAlias, string $column): string
    {
        $reference = sprintf('%s.%s', $rowAlias, SQLiteEventBus::quoteIdentifier($column));

        return sprintf(
            'CASE WHEN typeof(%s) = \'blob\' THEN hex(%s) ELSE %s END',
            $reference,
            $reference,
            $reference
        );
    }

    private function triggerName(string $table, string $action): string
    {
        return sprintf('__sqlite_events_%s_%s', substr(hash('sha256', $table), 0, 16), $action);
    }

    private function quoteLiteral(string $value): string
    {
        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }
}
