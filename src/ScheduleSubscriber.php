<?php

namespace Vivere\Laravel;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Events\Dispatcher;
use WeakMap;

/**
 * Turns the scheduler's own events into Vivere pings: start when a task starts,
 * then exactly one closing ping (success, the exit code, or fail with the error).
 *
 * Laravel dispatches both Finished and Failed for a foreground command that exits
 * non-zero, so each run is closed once and the second event is ignored.
 */
class ScheduleSubscriber
{
    /** @var WeakMap<Event, true> runs already closed, or skipped */
    private WeakMap $closed;

    public function __construct(
        private readonly Pinger $pinger,
        private readonly MonitorMap $map,
        private readonly TaskOptions $options,
    ) {
        $this->closed = new WeakMap();
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFinished::class => 'finished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
        ];
    }

    public function starting(ScheduledTaskStarting $e): void
    {
        unset($this->closed[$e->task]);
        // The scheduler dispatches Starting before it checks the overlap lock, and then
        // Finished with exit code 0 for a run it skipped. Reporting that as a success would
        // hide the case a heartbeat is for: a hung run holding the lock. Send nothing and
        // let the next due time pass.
        if ($e->task->withoutOverlapping && $this->locked($e->task)) {
            $this->closed[$e->task] = true;

            return;
        }
        if ($monitor = $this->monitorFor($e->task)) {
            $this->pinger->ping($monitor, 'start');
        }
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        // A background task reports when schedule:finish runs, with its real exit code.
        if ($e->task->runInBackground || ($e->task->skippedBecauseOverlapping ?? false)) {
            return;
        }
        $this->close($e->task, (int) $e->task->exitCode);
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $e): void
    {
        $this->close($e->task, (int) $e->task->exitCode);
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        if (isset($this->closed[$e->task]) || ! ($monitor = $this->monitorFor($e->task))) {
            return;
        }
        $this->closed[$e->task] = true;
        $message = get_class($e->exception) . ': ' . $e->exception->getMessage();
        $this->pinger->ping($monitor, 'fail', trim($message . "\n\n" . $this->outputTail($e->task)));
    }

    private function close(Event $task, int $exitCode): void
    {
        if (isset($this->closed[$task]) || ! ($monitor = $this->monitorFor($task))) {
            return;
        }
        $this->closed[$task] = true;
        if ($exitCode === 0) {
            $this->pinger->ping($monitor, '', $this->outputTail($task) ?: null);

            return;
        }
        // Vivere treats /<code> as the run's exit code: 0 is success, anything else a failure.
        $this->pinger->ping($monitor, (string) max(1, min(255, $exitCode)), $this->outputTail($task) ?: null);
    }

    private function locked(Event $task): bool
    {
        try {
            return $task->mutex->exists($task);
        } catch (\Throwable) {
            return false; // the cache is down; the scheduler will report that itself
        }
    }

    private function monitorFor(Event $task): ?string
    {
        if (! config('vivere.enabled', true) || ! $this->options->monitored($task)) {
            return null;
        }
        $t = new Task($task);
        if ($fixed = $t->fixedMonitor()) {
            return $fixed;
        }
        $key = $t->key();

        return $key === null ? null : $this->map->monitorFor($key);
    }

    /** The last few KB of the task's output file, if it writes one. */
    private function outputTail(Event $task): string
    {
        $path = $task->output;
        if (! config('vivere.send_output', true) || ! $path || $path === $task->getDefaultOutput() || ! is_file($path)) {
            return '';
        }
        $size = filesize($path);
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return '';
        }
        fseek($fh, max(0, $size - 8192));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);

        return trim($tail);
    }
}
