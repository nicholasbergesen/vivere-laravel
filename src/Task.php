<?php

namespace Vivere\Laravel;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * How a scheduled event is identified, named and scheduled on the Vivere side.
 */
class Task
{
    public function __construct(public readonly Event $event)
    {
    }

    /**
     * What the task runs, without the PHP binary and artisan path, so it reads the
     * same on every server: "backup:run --only-db", or the name of a closure or job.
     */
    public function identity(): ?string
    {
        $event = $this->event;
        if ($event instanceof CallbackEvent || $event->command === null) {
            $description = trim((string) $event->description);

            return $description === '' ? null : $description;
        }
        $command = preg_replace('/^.*?[\'"]?artisan[\'"]?\s+/', '', $event->command, 1);

        return trim($command);
    }

    /**
     * A short key for the task that survives schedule changes, so vivere:sync can
     * tell a task whose cron changed from a new one. Stored as a monitor tag.
     */
    public function key(): ?string
    {
        $identity = $this->identity();

        return $identity === null ? null : substr(sha1(static::app() . '|' . $identity), 0, 12);
    }

    public function name(): string
    {
        $custom = app(TaskOptions::class)->get($this->event)['name'];
        if ($custom) {
            return $custom;
        }
        $label = $this->event->description ?: $this->identity();

        return Str::limit(static::app() . ': ' . $label, 120, '');
    }

    public function cron(): string
    {
        return $this->event->getExpression();
    }

    public function timezone(): string
    {
        $tz = $this->event->timezone ?: config('app.timezone', 'UTC');

        return $tz instanceof \DateTimeZone ? $tz->getName() : (string) $tz;
    }

    public function grace(): string
    {
        return app(TaskOptions::class)->get($this->event)['grace'] ?: (string) config('vivere.grace', '5m');
    }

    /** A monitor id set with ->vivere(monitor: '...'), which skips the sync map. */
    public function fixedMonitor(): ?string
    {
        return app(TaskOptions::class)->get($this->event)['monitor'];
    }

    public static function app(): string
    {
        return Str::slug((string) (config('vivere.app') ?: config('app.name', 'laravel'))) ?: 'laravel';
    }
}
