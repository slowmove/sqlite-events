<?php

declare(strict_types=1);

namespace SQLiteEvents;

enum ChangeAction: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
    case Any = '*';
}
