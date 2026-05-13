# SQLite Events for PHP

SQLite Events is a small PHP library that lets application code react when rows
are inserted, updated, or deleted in a SQLite database.

PDO SQLite does not expose SQLite's native update hook to PHP. This package uses
ordinary SQLite triggers instead: it installs one trigger per table/action and
those triggers append change records to an internal event log table.

When you use `EventedPDO`, the package drains that log automatically after
successful statements and after transaction commits, so your application code
does not need an extra dispatch call.

## Install

Add to your `composer.json`

```json
  "repositories": {
    "sqlite-events": {
      "type": "vcs",
      "url": "git@github.com:Slowmove/sqlite-events.git"
    },
  }
```

then install

```bash
composer require slowmove/sqlite-events
```

For local development in this repository:

```bash
composer dump-autoload
composer test
```

## Testing

The test suite uses Pest:

```bash
composer test
```

Most tests use `sqlite::memory:` so each scenario gets a fresh database with no
setup or teardown files. The cross-connection test uses a temporary file-backed
SQLite database because separate `sqlite::memory:` PDO connections do not share
state.

## Basic Usage

```php
use SQLiteEvents\ChangeEvent;
use SQLiteEvents\ChangeAction;
use SQLiteEvents\EventedPDO;

$pdo = EventedPDO::connect('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->listen('*', ChangeAction::Any, function (ChangeEvent $event): void {
    printf(
        "%s on %s: %s\n",
        $event->action,
        $event->table,
        json_encode($event->new)
    );
});

$pdo->exec("INSERT INTO users (email) VALUES ('ada@example.com')");
```

`EventedPDO::connect()` installs event triggers automatically for existing
tables. Use `new EventedPDO(..., autoInstall: false)` or
`EventedPDO::connect(..., autoInstall: false)` when you want a normal SQLite
connection without event triggers.

The listener API is typed:

```php
$pdo->listen('users', ChangeAction::Insert, $handler);
$pdo->listen('users', ChangeAction::Update, $handler);
$pdo->listen('users', ChangeAction::Delete, $handler);
```

Use `ChangeAction::Any` for any insert, update, or delete on a table, and use
`*` as the table name to listen across all tables.

Passing object methods works like any other PHP callable:

```php
$pdo->listen('orders', ChangeAction::Insert, [$orderProjector, 'handleOrderInserted']);
```

## Keeping Triggers In Sync

SQLite requires triggers to be created per table. `EventedPDO::connect()`
handles existing tables automatically, but you should run `installEvents()`
after creating or migrating tables:

```php
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
$pdo->installEvents();
```

Calling `installEvents()` repeatedly is safe. Existing package triggers are
recreated. Virtual and SQLite shadow tables are skipped because SQLite does not
support ordinary triggers for them in the same way as user tables.

## Event Data

Listeners receive a `SQLiteEvents\ChangeEvent` object:

```php
$event->id;         // internal event id
$event->table;      // changed table name
$event->action;     // insert, update, or delete
$event->rowId;      // SQLite rowid when the table has one
$event->primaryKey; // primary-key column values, when present
$event->old;        // row values before update/delete
$event->new;        // row values after insert/update
$event->changedAt;  // UTC timestamp from SQLite
```

BLOB column values are emitted as hexadecimal strings because SQLite JSON cannot
store raw BLOB values.

## Delivery Model

With `EventedPDO`, events are dispatched automatically after successful
autocommit statements. Inside a transaction, dispatch waits until `commit()`.
Rolled-back writes are not dispatched.

Each log row is deleted only after every matching listener has completed
successfully. If a listener throws, the event remains in the log and can be
retried later.

The lower-level `SQLiteEventBus` API still exposes `dispatchPending()` for
worker-style setups, or when you need one PHP process to drain events written by
another connection.

This library is best suited for application-level workflows such as cache
invalidation, projections, audit hooks, search indexing, and lightweight domain
events. It is not a replacement for cross-process message queues when you need
distributed delivery guarantees.
