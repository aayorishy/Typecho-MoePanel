<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * Per-user / per-channel AI context stored outside the web root.
 */
final class AiConversationStore
{
    private const MAX_MESSAGES = 12;
    private const TTL = 604800;

    public function get(string $key): array
    {
        $all = $this->readAll();
        $row = $all[$this->hash($key)] ?? null;

        if (!is_array($row) || time() - (int) ($row['updated_at'] ?? 0) > self::TTL) {
            return [];
        }

        $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
        $messages = array_slice($messages, -self::MAX_MESSAGES);

        foreach ($messages as &$message) {
            if (is_array($message)) {
                $message['content'] = mb_substr((string) ($message['content'] ?? ''), 0, 300, 'UTF-8');
            }
        }
        unset($message);

        return $messages;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function save(string $key, array $messages): void
    {
        $messages = array_slice($messages, -self::MAX_MESSAGES);
        $this->mutate(function (array $all) use ($key, $messages): array {
            $all[$this->hash($key)] = [
                'messages' => $messages,
                'updated_at' => time(),
            ];

            if (count($all) > 500) {
                uasort($all, static fn(array $a, array $b): int => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0));
                $all = array_slice($all, 0, 300, true);
            }

            return $all;
        });
    }

    public function clear(string $key): void
    {
        $this->mutate(function (array $all) use ($key): array {
            unset($all[$this->hash($key)]);
            return $all;
        });
    }

    private function readAll(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if (!$handle) {
            return [];
        }

        @flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function mutate(callable $callback): void
    {
        $dir = dirname($this->path());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $handle = @fopen($this->path(), 'c+b');

        if (!$handle) {
            return;
        }

        @flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $all = json_decode((string) $raw, true);
        $all = is_array($all) ? $all : [];
        $all = $callback($all);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function path(): string
    {
        return sys_get_temp_dir() . '/moepanel-ai/conversations.json';
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
