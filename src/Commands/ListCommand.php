<?php

namespace Vivere\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Vivere\Laravel\Api;
use Vivere\Laravel\MonitorMap;
use Vivere\Laravel\Task;
use Vivere\Laravel\TaskOptions;

/**
 * Shows each scheduled task, the monitor it reports to, and that monitor's state.
 */
class ListCommand extends Command
{
    protected $signature = 'vivere:list {--offline : Don\'t ask Vivere for each monitor\'s status}';

    protected $description = 'List scheduled tasks and their Vivere monitors';

    public function handle(Schedule $schedule, MonitorMap $map, TaskOptions $options, Api $api): int
    {
        $rows = [];
        foreach ($schedule->events() as $event) {
            $task = new Task($event);
            $id = $task->fixedMonitor() ?? (($key = $task->key()) ? $map->monitorFor($key) : null);
            $status = match (true) {
                ! $options->monitored($event) => 'not monitored',
                $id === null => 'no monitor: run vivere:sync',
                (bool) $this->option('offline') => 'mapped',
                default => $this->status($api, $id),
            };
            $rows[] = [$task->name(), $task->cron(), $id ?? '-', $status];
        }
        $this->table(['Task', 'Cron', 'Monitor', 'Status'], $rows);

        return self::SUCCESS;
    }

    private function status(Api $api, string $id): string
    {
        try {
            $m = $api->monitor($id);
        } catch (\Throwable $e) {
            return 'unknown: ' . $e->getMessage();
        }
        if ($m === null) {
            return 'missing in Vivere: run vivere:sync';
        }
        $last = isset($m['last_ping_at']) ? ', last success ' . $m['last_ping_at'] : ', no successful run yet';

        return ($m['paused'] ?? false ? 'paused' : ($m['status'] ?? '?')) . $last;
    }
}
