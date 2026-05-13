<?php

declare(strict_types=1);

use SQLiteEvents\SQLiteEventBus;
use SQLiteEvents\EventedPDO;

function sqliteInMemory(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function sqliteEventBus(PDO $pdo): SQLiteEventBus
{
    $events = new SQLiteEventBus($pdo);
    $events->install();

    return $events;
}

function eventedSqliteInMemory(): EventedPDO
{
    $pdo = new EventedPDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function temporarySqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'sqlite-events-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary SQLite database path.');
    }

    return $path;
}
