<?php

declare(strict_types=1);

namespace SQLiteEvents;

final class ListenerRegistry
{
    /**
     * @var array<string, list<callable(ChangeEvent): void>>
     */
    private array $listeners = [];

    /**
     * @param callable(ChangeEvent): void $listener
     */
    public function add(string $event, callable $listener): void
    {
        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(ChangeEvent $event): void
    {
        foreach ($this->matchingListeners($event) as $listener) {
            $listener($event);
        }
    }

    /**
     * @return list<callable(ChangeEvent): void>
     */
    private function matchingListeners(ChangeEvent $event): array
    {
        $names = [
            '*',
            $event->action,
            $event->table.'.*',
            $event->table.'.'.$event->action,
        ];

        $matching = [];

        foreach ($names as $name) {
            foreach ($this->listeners[$name] ?? [] as $listener) {
                $matching[] = $listener;
            }
        }

        return $matching;
    }
}
