<?php

namespace Vivere\Laravel;

use Illuminate\Console\Scheduling\Event;
use WeakMap;

/**
 * Options set on scheduled events with ->vivere() and ->withoutVivere().
 * Kept beside the events rather than on them, because macros can't add properties.
 */
class TaskOptions
{
    /** @var WeakMap<Event, array{enabled: bool, grace: ?string, monitor: ?string, name: ?string}> */
    private WeakMap $options;

    public function __construct()
    {
        $this->options = new WeakMap();
    }

    public function set(Event $event, array $options): void
    {
        $this->options[$event] = array_merge($this->get($event), $options);
    }

    public function get(Event $event): array
    {
        return $this->options[$event] ?? ['enabled' => null, 'grace' => null, 'monitor' => null, 'name' => null];
    }

    /** Whether this task should report to Vivere, given the monitor_all setting. */
    public function monitored(Event $event): bool
    {
        return $this->get($event)['enabled'] ?? (bool) config('vivere.monitor_all', true);
    }
}
