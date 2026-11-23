<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

use RuntimeException;

/**
 * Minimal OpenAI-compatible client for DeepSeek chat completions.
 */
final class DeepSeekClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.deepseek.com', string $model = 'deepseek-chat')
    {
        $this->apiKey = trim((string) preg_replace('/^Bearer\s+/i', '', trim($apiKey)));
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.deepseek.com', '/');
        $this->model = trim($model !== '' ? $model : 'deepseek-chat');
    }

    public function hasKey(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function chat(array $messages, float $temperature = 0.8, int $maxTokens = 180): string
    {
        if ('' === $this->apiKey) {
            throw new RuntimeException('尚未配置 DeepSeek API Key');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器未启用 cURL 扩展');
        }

        $endpoint = str_ends_with($this->baseUrl, '/chat/completions')
            ? $this->baseUrl
            : $this->baseUrl . '/chat/completions';

        $payload = [
            'model' => $this->model,
            'messages' => array_values($messages),
            'temperature' => max(0.0, min(2.0, $temperature)),
            'max_tokens' => max(32, min(900, $maxTokens)),
            'stream' => false,
        ];

        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (false === $raw) {
            throw new RuntimeException('DeepSeek 请求失败：' . ('' !== $error ? $error : '网络错误'));
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('DeepSeek 返回了无法解析的响应（HTTP ' . $status . '）');
        }

        if ($status >= 400 || isset($decoded['error'])) {
            $message = (string) ($decoded['error']['message'] ?? ('HTTP ' . $status));

            if (401 === $status || false !== stripos($message, 'authentication')) {
                $message = 'API Key 无效或已过期';
            }

            throw new RuntimeException('DeepSeek API：' . mb_substr($message, 0, 220, 'UTF-8'));
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || '' === trim($content)) {
            throw new RuntimeException('DeepSeek 没有返回内容');
        }

        return trim($content);
    }
}
