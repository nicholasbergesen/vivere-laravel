<?php

namespace Vivere\Laravel;

/**
 * The task-key to monitor map that vivere:sync writes and the scheduler reads.
 * A JSON file, so it can be committed or built on deploy.
 */
class MonitorMap
{
    private ?array $entries = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, array{id: string, name: string, cron: string, timezone: string}> */
    public function all(): array
    {
        if ($this->entries === null) {
            $raw = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
            $this->entries = is_array($raw['monitors'] ?? null) ? $raw['monitors'] : [];
        }

        return $this->entries;
    }

    public function monitorFor(string $key): ?string
    {
        return $this->all()[$key]['id'] ?? null;
    }

    public function save(array $entries): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        ksort($entries);
        file_put_contents($this->path, json_encode(['monitors' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->entries = $entries;
    }

    public function path(): string
    {
        return $this->path;
    }
}
