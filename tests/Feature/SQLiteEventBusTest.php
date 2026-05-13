<?php

declare(strict_types=1);

use SQLiteEvents\ChangeEvent;
use SQLiteEvents\ChangeAction;
use SQLiteEvents\EventedPDO;
use SQLiteEvents\SQLiteEventBus;

it('automatically dispatches events after exec statements when using EventedPDO', function (): void {
    $pdo = eventedSqliteInMemory();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
    $pdo->installEvents();

    $seen = [];
    $pdo->listen('users', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event->new['email'];
    });

    $pdo->exec("INSERT INTO users (email) VALUES ('ada@example.com')");

    expect($seen)->toBe(['ada@example.com'])
        ->and($pdo->events()->pendingCount())->toBe(0);
});

it('can auto install event triggers when connecting to an existing database', function (): void {
    $path = temporarySqlitePath();

    try {
        $setup = new PDO('sqlite:' . $path);
        $setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $setup->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

        $pdo = EventedPDO::connect('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $seen = [];
        $pdo->listen('users', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
            $seen[] = $event->new['email'];
        });

        $pdo->exec("INSERT INTO users (email) VALUES ('auto@example.com')");

        expect($seen)->toBe(['auto@example.com']);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('can leave event triggers uninstalled when auto install is disabled', function (): void {
    $path = temporarySqlitePath();

    try {
        $setup = new PDO('sqlite:' . $path);
        $setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $setup->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

        $pdo = EventedPDO::connect('sqlite:' . $path, autoInstall: false);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $seen = [];
        $pdo->listen('users', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
            $seen[] = $event->new['email'];
        });

        $pdo->exec("INSERT INTO users (email) VALUES ('quiet@example.com')");

        expect($seen)->toBe([])
            ->and($pdo->events()->pendingCount())->toBe(0);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('automatically dispatches events after prepared statement execution when using EventedPDO', function (): void {
    $pdo = eventedSqliteInMemory();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
    $pdo->installEvents();

    $seen = [];
    $pdo->listen('users', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event->new['email'];
    });

    $statement = $pdo->prepare('INSERT INTO users (email) VALUES (:email)');
    $statement->execute([':email' => 'grace@example.com']);

    expect($seen)->toBe(['grace@example.com']);
});

it('waits until commit before auto dispatching transaction events', function (): void {
    $pdo = eventedSqliteInMemory();
    $pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL)');
    $pdo->installEvents();

    $seen = [];
    $pdo->listen('payments', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event->new['reference'];
    });

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO payments (reference) VALUES ('PAY-1')");

    expect($seen)->toBe([]);

    $pdo->commit();

    expect($seen)->toBe(['PAY-1'])
        ->and($pdo->events()->pendingCount())->toBe(0);
});

it('does not dispatch transaction events after rollback', function (): void {
    $pdo = eventedSqliteInMemory();
    $pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL)');
    $pdo->installEvents();

    $seen = [];
    $pdo->listen('payments', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event->new['reference'];
    });

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO payments (reference) VALUES ('PAY-1')");
    $pdo->rollBack();

    expect($seen)->toBe([])
        ->and($pdo->events()->pendingCount())->toBe(0);
});

it('dispatches insert, update, and delete events with old and new row payloads', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, name TEXT)');

    $events = sqliteEventBus($pdo);
    $seen = [];

    $events->listen('users', ChangeAction::Any, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event;
    });

    $pdo->exec("INSERT INTO users (email, name) VALUES ('ada@example.com', 'Ada')");
    $pdo->exec("UPDATE users SET name = 'Ada Lovelace' WHERE email = 'ada@example.com'");
    $pdo->exec("DELETE FROM users WHERE email = 'ada@example.com'");

    expect($events->pendingCount())->toBe(3)
        ->and($events->dispatchPending())->toBe(3)
        ->and($events->pendingCount())->toBe(0)
        ->and($seen)->toHaveCount(3);

    expect($seen[0])
        ->toBeInstanceOf(ChangeEvent::class)
        ->and($seen[0]->action)->toBe('insert')
        ->and($seen[0]->table)->toBe('users')
        ->and($seen[0]->old)->toBeNull()
        ->and($seen[0]->new)->toMatchArray([
            'email' => 'ada@example.com',
            'name' => 'Ada',
        ])
        ->and($seen[0]->primaryKey)->toBe(['id' => 1]);

    expect($seen[1]->action)->toBe('update')
        ->and($seen[1]->old['name'])->toBe('Ada')
        ->and($seen[1]->new['name'])->toBe('Ada Lovelace');

    expect($seen[2]->action)->toBe('delete')
        ->and($seen[2]->old['email'])->toBe('ada@example.com')
        ->and($seen[2]->new)->toBeNull();
});

it('can call any PHP callable registered as a listener', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL)');

    $events = sqliteEventBus($pdo);
    $handler = new class {
        public array $references = [];

        public function handleOrderInserted(ChangeEvent $event): void
        {
            $this->references[] = $event->new['reference'];
        }
    };

    $events->listen('orders', ChangeAction::Insert, [$handler, 'handleOrderInserted']);

    $pdo->exec("INSERT INTO orders (reference) VALUES ('ORDER-1001')");
    $events->dispatchPending();

    expect($handler->references)->toBe(['ORDER-1001']);
});

it('matches wildcard, action, table wildcard, and table action listeners in registration order', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL)');

    $events = sqliteEventBus($pdo);
    $calls = [];

    $events->listen('*', ChangeAction::Any, function () use (&$calls): void {
        $calls[] = '*';
    });
    $events->listen('*', ChangeAction::Insert, function () use (&$calls): void {
        $calls[] = 'insert';
    });
    $events->listen('products', ChangeAction::Any, function () use (&$calls): void {
        $calls[] = 'products.*';
    });
    $events->listen('products', ChangeAction::Insert, function () use (&$calls): void {
        $calls[] = 'products.insert';
    });

    $pdo->exec("INSERT INTO products (sku) VALUES ('SKU-1')");
    $events->dispatchPending();

    expect($calls)->toBe(['*', 'insert', 'products.*', 'products.insert']);
});

it('leaves an event pending when a listener throws so it can be retried', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT NOT NULL)');

    $events = sqliteEventBus($pdo);
    $attempts = 0;

    $events->listen('invoices', ChangeAction::Insert, function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('Projection store is temporarily unavailable.');
        }
    });

    $pdo->exec("INSERT INTO invoices (number) VALUES ('INV-1')");

    expect(fn () => $events->dispatchPending())
        ->toThrow(RuntimeException::class, 'Projection store is temporarily unavailable.');

    expect($events->pendingCount())->toBe(1);

    expect($events->dispatchPending())->toBe(1)
        ->and($attempts)->toBe(2)
        ->and($events->pendingCount())->toBe(0);
});

it('syncs triggers after migrations when install is called again', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

    $events = sqliteEventBus($pdo);
    $seen = [];

    $events->listen('posts', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event->new['title'];
    });

    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
    $events->install();

    $pdo->exec("INSERT INTO posts (title) VALUES ('Hello from a migration')");
    $events->dispatchPending();

    expect($seen)->toBe(['Hello from a migration']);
});

it('records blob column values as hexadecimal strings', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY AUTOINCREMENT, data BLOB NOT NULL)');

    $events = sqliteEventBus($pdo);
    $seen = [];

    $events->listen('files', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event;
    });

    $pdo->exec("INSERT INTO files (data) VALUES (x'CAFE')");
    $events->dispatchPending();

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->new['data'])->toBe('CAFE');
});

it('ignores SQLite virtual tables and their shadow tables', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE VIRTUAL TABLE docs USING fts5(body)');

    $events = sqliteEventBus($pdo);

    $pdo->exec("INSERT INTO docs (body) VALUES ('searchable text')");

    expect($events->pendingCount())->toBe(0);
});

it('dispatches changes made from another SQLite connection', function (): void {
    $path = temporarySqlitePath();

    try {
        $first = new PDO('sqlite:' . $path);
        $first->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $first->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');

        $events = sqliteEventBus($first);

        $second = new PDO('sqlite:' . $path);
        $second->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $second->exec("INSERT INTO posts (title) VALUES ('From another connection')");

        $seen = [];
        $events->listen('posts', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        expect($events->dispatchPending())->toBe(1)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0]->new['title'])->toBe('From another connection');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('supports WITHOUT ROWID tables by using primary keys instead of rowid', function (): void {
    $pdo = sqliteInMemory();
    $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID');

    $events = sqliteEventBus($pdo);
    $seen = [];

    $events->listen('settings', ChangeAction::Insert, function (ChangeEvent $event) use (&$seen): void {
        $seen[] = $event;
    });

    $pdo->exec("INSERT INTO settings (name, value) VALUES ('theme', 'dark')");
    $events->dispatchPending();

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->rowId)->toBeNull()
        ->and($seen[0]->primaryKey)->toBe(['name' => 'theme']);
});
