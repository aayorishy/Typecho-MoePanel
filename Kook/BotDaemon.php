<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * KOOK WebSocket Bot daemon.
 *
 * Flow: gateway -> hello -> event -> handlers -> ping/pong, with session resume
 * and reconnect/backoff.
 */
final class BotDaemon
{
    private KookClient $client;
    private ?WebSocketConnection $socket = null;
    private string $sessionId = '';
    private int $lastSn = 0;
    private float $nextPingAt = 0.0;
    private float $pingSentAt = 0.0;
    private bool $awaitingPong = false;
    private bool $resuming = false;
    private bool $running = true;
    private float $lastHeartbeat = 0.0;
    private string $botId = '';
    private array $settings;

    public function __construct(string $token, array $settings)
    {
        $this->client = new KookClient($token);
        $this->client->setTimeout(10);
        $this->settings = $settings;
    }

    public function run(): void
    {
        try {
            $me = $this->client->me();
            $this->botId = (string) ($me['id'] ?? '');
            $this->log('WebSocket Bot 身份', ['bot_id' => $this->botId]);
        } catch (\Throwable $exception) {
            $this->log('读取 Bot 身份失败', ['error' => $exception->getMessage()]);
        }

        $session = $this->loadSession();
        $this->sessionId = (string) ($session['session_id'] ?? '');
        $this->lastSn = (int) ($session['sn'] ?? 0);
        $backoff = 2;
        $reconnects = 0;
        $this->log('WebSocket Bot 启动', ['resume' => '' !== $this->sessionId, 'sn' => $this->lastSn]);

        while ($this->running) {
            $this->heartbeat();

            try {
                $this->resuming = '' !== $this->sessionId;
                $this->connectAndRun();
                $backoff = 2;
            } catch (\Throwable $exception) {
                $this->closeSocket();
                $reconnects++;
                $this->log('WebSocket 连接中断', [
                    'error' => $exception->getMessage(),
                    'resume' => '' !== $this->sessionId,
                    'sn' => $this->lastSn,
                    'reconnects' => $reconnects,
                ]);
            }

            if (!$this->running) {
                break;
            }

            $sleep = min(60, max(2, $backoff + random_int(0, 3)));
            $this->log('WebSocket 即将重连', ['seconds' => $sleep, 'attempt' => $reconnects + 1]);
            sleep($sleep);
            $backoff = min(60, max(2, $backoff * 2));
            $session = $this->loadSession();

            if ([] !== $session) {
                $this->sessionId = (string) ($session['session_id'] ?? $this->sessionId);
                $this->lastSn = (int) ($session['sn'] ?? $this->lastSn);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->closeSocket();
    }

    private function connectAndRun(): void
    {
        $gateway = $this->client->gatewayUrl();

        if ($this->resuming && '' !== $this->sessionId) {
            $gateway .= (str_contains($gateway, '?') ? '&' : '?') . http_build_query([
                'resume' => 1,
                'sn' => $this->lastSn,
                'session_id' => $this->sessionId,
            ]);
        }

        $socket = new WebSocketConnection($gateway);
        $socket->connect();
        $this->socket = $socket;
        $this->awaitingPong = false;
        $this->nextPingAt = microtime(true) + random_int(24, 34);
        $helloAt = microtime(true);
        $hello = false;

        while ($this->running) {
            $this->heartbeat();
            $message = $socket->readMessage(1.0);

            if (null !== $message) {
                $packet = json_decode($message, true);

                if (is_array($packet)) {
                    $signal = (int) ($packet['s'] ?? -1);
                    $data = is_array($packet['d'] ?? null) ? $packet['d'] : [];

                    if (1 === $signal) {
                        $code = (int) ($data['code'] ?? -1);

                        if (0 !== $code) {
                            if (in_array($code, [40107, 40108], true)) {
                                $this->clearSession();
                            }

                            throw new WebSocketException('Gateway HELLO 失败，code=' . $code);
                        }

                        $hello = true;
                        $this->sessionId = (string) ($data['session_id'] ?? $this->sessionId);

                        if (!$this->resuming) {
                            $this->lastSn = 0;
                        }

                        $this->saveSession();
                        $this->log('WebSocket 已连接', [
                            'session_id' => $this->sessionId,
                            'resume' => $this->resuming,
                            'sn' => $this->lastSn,
                        ]);
                    } elseif (0 === $signal) {
                        $this->acceptEvent($packet);
                    } elseif (3 === $signal) {
                        $this->awaitingPong = false;
                        $this->nextPingAt = microtime(true) + random_int(24, 34);
                    } elseif (5 === $signal) {
                        $this->clearSession();
                        throw new WebSocketException('服务端要求重新连接');
                    } elseif (6 === $signal) {
                        $this->sessionId = (string) ($data['session_id'] ?? $this->sessionId);
                        $this->saveSession();
                        $this->log('WebSocket 会话恢复完成', ['sn' => $this->lastSn]);
                    } elseif (9 === $signal) {
                        $this->clearSession();
                        throw new WebSocketException('会话已失效，重新建立连接');
                    }
                }
            }

            if (!$hello && microtime(true) - $helloAt > 6.5) {
                throw new WebSocketTimeoutException('等待 Gateway HELLO 超时');
            }

            $now = microtime(true);

            if ($this->awaitingPong && $now - $this->pingSentAt > 6.0) {
                throw new WebSocketTimeoutException('WebSocket 心跳超时');
            }

            if (!$this->awaitingPong && $now >= $this->nextPingAt) {
                $this->sendPing();
            }
        }
    }

    private function acceptEvent(array $packet): void
    {
        $sn = (int) ($packet['sn'] ?? 0);
        $type = (string) ($packet['d']['extra']['type'] ?? '');

        // 按钮点击必须处理；恢复会话时 sn 可能不大于本地记录，但角色操作
        // 本身是幂等的，不能因此丢掉用户的领取/移除请求。
        if ($sn > 0 && $sn <= $this->lastSn && 'message_btn_click' !== $type) {
            return;
        }

        $this->processPacket($packet);
    }

    private function processPacket(array $packet): void
    {
        $sn = (int) ($packet['sn'] ?? 0);

        if ($sn > $this->lastSn) {
            $this->lastSn = $sn;
        }

        $event = $packet['d'] ?? null;

        if (is_array($event)) {
            $extra = is_array($event['extra'] ?? null) ? $event['extra'] : [];
            $type = (string) ($extra['type'] ?? '');

            try {
                RoleClaim::handleEvent($packet, $this->settings);
                Welcome::handleEvent($packet, $this->settings);
            } catch (\Throwable $exception) {
                $this->log('角色/欢迎事件处理失败', ['type' => $type, 'error' => $exception->getMessage()]);
            }

            if (class_exists(AiChatService::class)) {
                $eventContent = (string) ($event['content'] ?? ($extra['content'] ?? ''));

                if (false !== strpos($eventContent, '(met)') || false !== strpos($eventContent, '@')) {
                    @file_put_contents(
                        sys_get_temp_dir() . '/moepanel-events.log',
                        '[' . date('Y-m-d H:i:s') . '] ' . json_encode([
                            'type' => $type,
                            'bot_id' => $this->botId,
                            'content' => mb_substr($eventContent, 0, 300, 'UTF-8'),
                            'extra' => $extra,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                    @chmod(sys_get_temp_dir() . '/moepanel-events.log', 0666);

                    $this->log('AI 入口', [
                        'type' => $type,
                        'bot_id' => $this->botId,
                        'content' => mb_substr($eventContent, 0, 180, 'UTF-8'),
                    ]);
                }

                try {
                    AiChatService::handleEvent($packet, $this->botId);
                } catch (\Throwable $exception) {
                    $this->log('AI 事件处理失败', ['type' => $type, 'error' => $exception->getMessage()]);
                }
            }

            $this->log('WebSocket 收到事件', ['type' => $type, 'sn' => $sn]);
        }

        $this->saveSession();
    }

    private function sendPing(): void
    {
        if (null === $this->socket) {
            return;
        }

        $this->socket->sendJson(['s' => 2, 'sn' => $this->lastSn]);
        $this->awaitingPong = true;
        $this->pingSentAt = microtime(true);
        $this->nextPingAt = $this->pingSentAt + random_int(24, 34);
    }

    private function closeSocket(): void
    {
        if (null !== $this->socket) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    private function sessionFile(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moepanel-kook-bot';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . '/ws-session.json';
    }

    private function saveSession(): void
    {
        if ('' === $this->sessionId) {
            return;
        }

        @file_put_contents($this->sessionFile(), json_encode([
            'session_id' => $this->sessionId,
            'sn' => $this->lastSn,
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function clearSession(): void
    {
        $this->sessionId = '';
        $this->lastSn = 0;
        $this->resuming = false;
        @unlink($this->sessionFile());
    }

    private function loadSession(): array
    {
        $file = $this->sessionFile();

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function heartbeat(): void
    {
        $now = microtime(true);

        if ($now - $this->lastHeartbeat < 10) {
            return;
        }

        $this->lastHeartbeat = $now;
        $dir = sys_get_temp_dir() . '/moepanel-kook-bot';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($dir . '/heartbeat', (string) time());
    }

    private function log(string $message, array $context = []): void
    {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $message
            . ([] !== $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
            . PHP_EOL);
    }
}
