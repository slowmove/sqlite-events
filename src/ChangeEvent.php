<?php

declare(strict_types=1);

namespace SQLiteEvents;

final readonly class ChangeEvent
{
    /**
     * @param array<string, mixed> $primaryKey
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function __construct(
        public int $id,
        public string $table,
        public string $action,
        public ?string $rowId,
        public array $primaryKey,
        public ?array $old,
        public ?array $new,
        public string $changedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $payload = self::decodeJsonObject($row['payload'] ?? null);

        return new self(
            id: (int) $row['id'],
            table: (string) $row['table_name'],
            action: (string) $row['action'],
            rowId: $row['row_id'] === null ? null : (string) $row['row_id'],
            primaryKey: self::decodeJsonObject($row['primary_key'] ?? null),
            old: self::decodeNullableJsonObject($payload['old'] ?? null),
            new: self::decodeNullableJsonObject($payload['new'] ?? null),
            changedAt: (string) $row['changed_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeNullableJsonObject(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
