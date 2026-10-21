<?php

namespace Vivere\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Vivere\Laravel\Api;
use Vivere\Laravel\MonitorMap;
use Vivere\Laravel\Task;
use Vivere\Laravel\TaskOptions;

/**
 * Creates a Vivere monitor for each scheduled task, with the task's own cron
 * expression and timezone, and writes the map the scheduler pings from.
 * Safe to run on every deploy: tasks that already have a monitor are left alone.
 */
class SyncCommand extends Command
{
    protected $signature = 'vivere:sync
        {--dry-run : Show what would change without changing anything}
        {--force : Replace monitors whose schedule changed (their run history starts again)}
        {--prune : Delete monitors for tasks that are no longer scheduled}';

    protected $description = 'Create Vivere monitors for your scheduled tasks';

    public function handle(Schedule $schedule, Api $api, MonitorMap $map, TaskOptions $options): int
    {
        try {
            return $this->sync($schedule, $api, $map, $options);
        } catch (RuntimeException|ConnectionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function sync(Schedule $schedule, Api $api, MonitorMap $map, TaskOptions $options): int
    {
        $dry = (bool) $this->option('dry-run');
        $app = Task::app();
        $appTag = 'laravel-app:' . $app;

        $existing = [];
        foreach ($api->monitors() as $m) {
            if (preg_match('/laravel-key:([0-9a-f]{12})/', (string) ($m['tags'] ?? ''), $k) && str_contains((string) $m['tags'], $appTag)) {
                $existing[$k[1]] = $m;
            }
        }

        $entries = [];
        $rows = [];
        $changed = 0;
        $failed = 0;
        foreach ($schedule->events() as $event) {
            $task = new Task($event);
            if (! $options->monitored($event) || $task->fixedMonitor()) {
                continue;
            }
            $key = $task->key();
            if ($key === null) {
                $rows[] = ['-', $event->getSummaryForDisplay(), $task->cron(), 'skipped: give the closure a ->name()'];
                continue;
            }
            if (isset($entries[$key])) {
                $rows[] = ['-', $task->name(), $task->cron(), 'skipped: same task scheduled twice; give one a ->name()'];
                continue;
            }
            $want = ['name' => $task->name(), 'cron' => $task->cron(), 'timezone' => $task->timezone()];
            $have = $existing[$key] ?? null;
            unset($existing[$key]);

            if ($have && ($have['cron'] ?? '') === $want['cron'] && ($have['timezone'] ?? '') === $want['timezone']) {
                $entries[$key] = ['id' => $have['id']] + $want;
                $rows[] = [$key, $want['name'], $want['cron'], 'ok'];
                continue;
            }
            if ($have && ! $this->option('force')) {
                $entries[$key] = ['id' => $have['id'], 'name' => $have['name'], 'cron' => $have['cron'] ?? '', 'timezone' => $have['timezone'] ?? ''];
                $rows[] = [$key, $want['name'], $want['cron'], "schedule changed from {$have['cron']}; run with --force to replace"];
                continue;
            }
            $changed++;
            if ($dry) {
                $rows[] = [$key, $want['name'], $want['cron'], $have ? 'would replace' : 'would create'];
                continue;
            }
            if ($have) {
                $api->delete($have['id']);
            }
            $body = $want + [
                'kind' => 'heartbeat',
                'grace' => $task->grace(),
                'tags' => "laravel, {$appTag}, laravel-key:{$key}",
                'description' => 'Laravel scheduled task: ' . ($task->identity() ?? $want['name']) . '. Managed by vivere/laravel.',
            ];
            if ($page = config('vivere.page')) {
                $body['page'] = (int) $page;
            }
            try {
                $created = $api->create($body);
            } catch (RuntimeException $e) {
                // One task Vivere won't take (a plan limit, an odd cron) shouldn't stop the rest.
                $failed++;
                $rows[] = [$key, $want['name'], $want['cron'], 'failed: ' . $e->getMessage()];
                continue;
            }
            $entries[$key] = ['id' => $created['id']] + $want;
            $rows[] = [$key, $want['name'], $want['cron'], $have ? 'replaced' : 'created'];
        }

        foreach ($existing as $key => $m) {
            if ($this->option('prune')) {
                $changed++;
                if (! $dry) {
                    $api->delete($m['id']);
                }
                $rows[] = [$key, $m['name'], $m['cron'] ?? '', $dry ? 'would delete' : 'deleted'];
            } else {
                $rows[] = [$key, $m['name'], $m['cron'] ?? '', 'no longer scheduled; --prune deletes it'];
            }
        }

        $this->table(['Key', 'Monitor', 'Cron', 'Result'], $rows);
        if ($dry) {
            $this->info("Dry run: {$changed} change(s) not made.");

            return self::SUCCESS;
        }
        $map->save($entries);
        $this->info(count($entries) . " task(s) monitored. Map written to {$map->path()}.");
        if ($failed) {
            $this->error("{$failed} monitor(s) could not be created; see the table above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
