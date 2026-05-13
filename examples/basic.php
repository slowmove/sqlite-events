<?php

declare(strict_types=1);

use SQLiteEvents\ChangeEvent;
use SQLiteEvents\ChangeAction;
use SQLiteEvents\EventedPDO;

require __DIR__ . '/../vendor/autoload.php';

$pdo = new EventedPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
$pdo->installEvents();

$pdo->listen('users', ChangeAction::Insert, function (ChangeEvent $event): void {
    echo 'Inserted user: ' . $event->new['email'] . PHP_EOL;
});

$pdo->exec("INSERT INTO users (email) VALUES ('ada@example.com')");
