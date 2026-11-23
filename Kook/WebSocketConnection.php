<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * Dependency-free RFC 6455 client for the KOOK gateway.
 */
final class WebSocketConnection
{
    /** @var resource|null */
    private $stream = null;
    private string $buffer = '';
    private bool $connected = false;

    public function __construct(
        private readonly string $url,
        private readonly float $connectTimeout = 12.0
    ) {
    }

    public function connect(): void
    {
        $parts = parse_url($this->url);

        if (!is_array($parts) || empty($parts['host'])) {
            throw new WebSocketException('无效的 WebSocket 地址');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'wss'));
        $secure = 'wss' === $scheme;

        if (!in_array($scheme, ['ws', 'wss'], true)) {
            throw new WebSocketException('不支持的 WebSocket 协议：' . $scheme);
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        $path = '' !== $path ? $path : '/';

        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $transport = $secure ? 'tls' : 'tcp';
        $ssl = [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        $context = stream_context_create($secure ? ['ssl' => $ssl] : []);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($stream)) {
            throw new WebSocketException('WebSocket 连接失败：' . ('' !== $errstr ? $errstr : 'unknown error'));
        }

        stream_set_blocking($stream, true);
        stream_set_timeout($stream, (int) ceil($this->connectTimeout));
        $this->stream = $stream;

        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . ((($secure && 443 === $port) || (!$secure && 80 === $port)) ? '' : ':' . $port);
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$hostHeader}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: MoePanel-KOOK-Bot/" . Settings::VERSION . "\r\n\r\n";
        $this->writeRaw($request);

        $header = '';

        while (false !== ($line = fgets($stream, 4096))) {
            $header .= $line;

            if ("\r\n" === $line || "\n" === $line) {
                break;
            }

            if (strlen($header) > 32768) {
                $this->close();
                throw new WebSocketException('WebSocket 握手响应过长');
            }
        }

        if ('' === $header || !preg_match('#^HTTP/1\.[01]\s+101#', $header)) {
            $this->close();
            throw new WebSocketException('WebSocket 握手失败：' . substr(strip_tags($header), 0, 180));
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (!preg_match('/^Sec-WebSocket-Accept:\s*(.+)$/mi', $header, $match)
            || trim($match[1]) !== $expected) {
            $this->close();
            throw new WebSocketException('WebSocket 握手校验失败');
        }

        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->stream) && !feof($this->stream);
    }

    public function readMessage(float $idleTimeout = 1.0): ?string
    {
        if (!$this->isConnected()) {
            throw new WebSocketException('WebSocket 连接已断开');
        }

        $first = $this->readExactly(2, $idleTimeout, true);

        if (null === $first) {
            return null;
        }

        $fragments = '';

        while (true) {
            if (strlen($first) < 2) {
                throw new WebSocketException('WebSocket 帧头不完整');
            }

            $byte1 = ord($first[0]);
            $byte2 = ord($first[1]);
            $fin = 0 !== ($byte1 & 0x80);
            $opcode = $byte1 & 0x0F;
            $masked = 0 !== ($byte2 & 0x80);
            $length = $byte2 & 0x7F;

            if (126 === $length) {
                $length = unpack('n', $this->readExactly(2, 6.0))[1];
            } elseif (127 === $length) {
                $parts = unpack('N2', $this->readExactly(8, 6.0));
                $length = ((int) $parts[1] << 32) | (int) $parts[2];
            }

            if ($length < 0 || $length > 8 * 1024 * 1024) {
                throw new WebSocketException('WebSocket 帧长度异常');
            }

            $mask = $masked ? $this->readExactly(4, 6.0) : '';
            $payload = $length > 0 ? $this->readExactly($length, 8.0) : '';

            if ($masked) {
                $payload = self::applyMask($payload, $mask);
            }

            if (0x8 === $opcode) {
                $this->close();
                throw new WebSocketException('WebSocket 服务端关闭连接');
            }

            if (0x9 === $opcode) {
                $this->sendFrame(0xA, $payload);
                $first = $this->readExactly(2, 6.0);
                continue;
            }

            if (0xA === $opcode) {
                $first = $this->readExactly(2, 6.0);
                continue;
            }

            if (0x1 === $opcode || 0x2 === $opcode) {
                $fragments = $payload;
            } elseif (0x0 === $opcode) {
                $fragments .= $payload;
            } else {
                $first = $this->readExactly(2, 6.0);
                continue;
            }

            if ($fin) {
                return $fragments;
            }

            $first = $this->readExactly(2, 8.0);
        }
    }

    public function sendJson(array $payload): void
    {
        $this->sendFrame(0x1, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
        $this->connected = false;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if (!$this->isConnected()) {
            throw new WebSocketException('WebSocket 未连接');
        }

        $length = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));

        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        $this->writeRaw($header . $mask . $masked);
    }

    private function readExactly(int $length, float $timeout, bool $allowIdle = false): ?string
    {
        $deadline = microtime(true) + $timeout;

        while (strlen($this->buffer) < $length) {
            if (!is_resource($this->stream)) {
                throw new WebSocketException('WebSocket 连接已断开');
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                if ($allowIdle && '' === $this->buffer) {
                    return null;
                }

                throw new WebSocketTimeoutException('WebSocket 读取超时');
            }

            stream_set_timeout($this->stream, max(1, (int) ceil($remaining)));
            $chunk = @fread($this->stream, $length - strlen($this->buffer));

            if (false === $chunk || '' === $chunk) {
                if (feof($this->stream)) {
                    throw new WebSocketException('WebSocket 连接已关闭');
                }

                $meta = stream_get_meta_data($this->stream);

                if (!empty($meta['timed_out'])) {
                    if ($allowIdle && '' === $this->buffer) {
                        return null;
                    }

                    throw new WebSocketTimeoutException('WebSocket 读取超时');
                }

                continue;
            }

            $this->buffer .= $chunk;
        }

        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $result;
    }

    private function writeRaw(string $data): void
    {
        if (!is_resource($this->stream)) {
            throw new WebSocketException('WebSocket 未连接');
        }

        $offset = 0;
        $total = strlen($data);

        while ($offset < $total) {
            $written = @fwrite($this->stream, substr($data, $offset));

            if (false === $written || 0 === $written) {
                throw new WebSocketException('WebSocket 写入失败');
            }

            $offset += $written;
        }
    }

    private static function applyMask(string $payload, string $mask): string
    {
        $length = strlen($payload);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $payload[$i] ^ $mask[$i % 4];
        }

        return $result;
    }
}
