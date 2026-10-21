<?php

namespace Vivere\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The few calls vivere:sync and vivere:list make to Vivere's REST API.
 */
class Api
{
    /**
     * Reads and deletes retry on connection errors. Creates never retry: a request that
     * timed out may still have created the monitor, and a retry would make a second one.
     */
    private function client(bool $retry = true): PendingRequest
    {
        $key = config('vivere.api_key');
        if (! $key) {
            throw new RuntimeException('Set VIVERE_API_KEY (an API key from https://vivere.dev/app/keys) to sync monitors.');
        }

        $client = Http::baseUrl(rtrim((string) config('vivere.base_url', 'https://vivere.dev'), '/') . '/api/v1')
            ->withToken($key)
            ->withUserAgent('vivere-laravel')
            ->acceptJson()
            ->timeout(20);

        return $retry ? $client->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false) : $client;
    }

    /** @return array<int, array> */
    public function monitors(): array
    {
        $r = $this->client()->get('/monitors');
        $this->check($r, 'list monitors');
        $body = $r->json();

        return $body['monitors'] ?? (array_is_list((array) $body) ? $body : []);
    }

    public function create(array $monitor): array
    {
        $r = $this->client(retry: false)->post('/monitors', $monitor);
        $this->check($r, "create the monitor \"{$monitor['name']}\"");

        return $r->json('monitor') ?? $r->json();
    }

    public function delete(string $id): void
    {
        $r = $this->client()->delete('/monitors/' . rawurlencode($id));
        if ($r->status() !== 404) {
            $this->check($r, "delete monitor {$id}");
        }
    }

    public function monitor(string $id): ?array
    {
        $r = $this->client()->get('/monitors/' . rawurlencode($id));
        if ($r->status() === 404) {
            return null;
        }
        $this->check($r, "read monitor {$id}");

        return $r->json('monitor') ?? $r->json();
    }

    private function check($response, string $what): void
    {
        if ($response->successful()) {
            return;
        }
        $message = $response->json('error') ?? substr((string) $response->body(), 0, 300);
        throw new RuntimeException("Vivere could not {$what}: HTTP {$response->status()} {$message}");
    }
}
