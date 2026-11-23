<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

use Throwable;

/**
 * KOOK @mention -> DeepSeek role-play chat.
 *
 * Replies are plain KMarkdown messages, never CardMessage.
 */
final class AiChatService
{
    private const MAX_MESSAGES = 12;
    private const MAX_MESSAGE_CHARS = 300;
    private const MAX_PROMPT_CHARS = 2400;

    private array $settings;
    private string $token;
    private KookClient $client;
    private AiConversationStore $store;

    public function __construct(array $settings, string $token)
    {
        $this->settings = $settings;
        $this->token = $token;
        $this->client = new KookClient($token);
        $this->client->setTimeout(12);
        $this->store = new AiConversationStore();
    }

    public static function handleEvent(array $packet, string $botId): void
    {
        $settings = Settings::reload();

        $event = is_array($packet['d'] ?? null) ? $packet['d'] : [];
        $extra = is_array($event['extra'] ?? null) ? $event['extra'] : [];
        $content = (string) ($event['content'] ?? ($extra['content'] ?? ''));
        $looksLikeMention = false !== strpos($content, '(met)') || false !== strpos($content, '@');

        if ('' !== $content) {
            self::log('AI 入口', [
                'ai_enabled' => (string) ($settings['aiEnabled'] ?? '0'),
                'bot_id' => $botId,
                'content' => mb_substr($content, 0, 200, 'UTF-8'),
                'mention' => $extra['mention'] ?? ($event['mention'] ?? []),
            ]);
        }

        if ('1' !== (string) ($settings['aiEnabled'] ?? '0')) {
            if ($looksLikeMention) {
                self::log('AI 跳过：角色扮演未启用', ['content' => mb_substr($content, 0, 160, 'UTF-8')]);
            }
            return;
        }

        $token = trim((string) ($settings['botToken'] ?? ''));

        if ('' === $token) {
            if ($looksLikeMention) {
                self::log('AI 跳过：Bot Token 为空');
            }
            return;
        }

        try {
            (new self($settings, $token))->handlePacket($packet, $botId);
        } catch (Throwable $exception) {
            self::log('AI 处理失败', ['error' => $exception->getMessage()]);
        }
    }

    public function handlePacket(array $packet, string $botId): void
    {
        $event = is_array($packet['d'] ?? null) ? $packet['d'] : [];
        $extra = is_array($event['extra'] ?? null) ? $event['extra'] : [];
        $author = is_array($extra['author'] ?? null) ? $extra['author'] : [];
        $userId = (string) ($author['id'] ?? $event['author_id'] ?? '');
        $authorBot = !empty($author['bot']);
        $channelId = (string) ($event['target_id'] ?? '');
        $guildId = (string) ($extra['guild_id'] ?? ($this->settings['guildId'] ?? ''));
        $content = (string) ($event['content'] ?? ($extra['content'] ?? ''));

        if ('' === $userId || '' === $channelId || $authorBot || $userId === $botId) {
            return;
        }

        $botName = '';

        if ('' === $botId) {
            try {
                $me = $this->client->me();
                $botId = (string) ($me['id'] ?? '');
                $botName = (string) ($me['username'] ?? $me['nickname'] ?? '');
            } catch (Throwable $ignored) {
            }
        }

        if ('' === $botId) {
            self::log('AI 跳过：Bot ID 为空', ['channel_id' => $channelId]);
            return;
        }

        if ('' === $botName && false !== strpos($content, '@')) {
            try {
                $me = $this->client->me();
                $botName = (string) ($me['username'] ?? $me['nickname'] ?? '');
            } catch (Throwable $ignored) {
            }
        }

        // 角色扮演只响应明确 @机器人 的消息。频道自动回复不能绕过 mention，
        // 否则普通聊天也会触发 AI。
        if (!$this->isBotMentioned($content, $event, $extra, $botId, $botName)) {
            if (false !== strpos($content, '(met)') || false !== strpos($content, '@')) {
                self::log('AI 跳过：未匹配到机器人 mention', [
                    'bot_id' => $botId,
                    'content' => mb_substr($content, 0, 180, 'UTF-8'),
                ]);
            }
            return;
        }

        self::log('AI 收到 mention', [
            'user_id' => $userId,
            'channel_id' => $channelId,
            'content' => mb_substr($content, 0, 180, 'UTF-8'),
        ]);

        $text = $this->cleanMention($content, $botId, $botName);

        if ('' === $text) {
            $this->reply($channelId, $userId, '在的，想聊点什么？');
            return;
        }

        // Context follows the user across channels in the same guild.
        $key = $guildId . ':' . $userId . ':' . substr(sha1($this->systemPrompt()), 0, 8);

        if (preg_match('/^(重置|reset|\/reset|清空对话|清除上下文)$/iu', $text)) {
            $this->store->clear($key);
            $this->reply($channelId, $userId, '上下文已经清空，我们重新开始。');
            return;
        }

        $apiKey = trim((string) ($this->settings['aiApiKey'] ?? ''));

        if ('' === $apiKey) {
            $this->reply($channelId, $userId, 'DeepSeek 还没有配置 API Key。请让站长先在「角色扮演」中填写。');
            return;
        }

        $historyLimit = max(2, min(12, (int) ($this->settings['aiHistoryLimit'] ?? 6)));
        $history = $this->store->get($key);
        $history[] = ['role' => 'user', 'content' => mb_substr($text, 0, self::MAX_MESSAGE_CHARS, 'UTF-8')];
        $history = array_slice($history, -$historyLimit);

        $messages = [['role' => 'system', 'content' => $this->systemPrompt()]];

        foreach ($history as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = [
                'role' => (string) $message['role'],
                'content' => mb_substr((string) ($message['content'] ?? ''), 0, self::MAX_MESSAGE_CHARS, 'UTF-8'),
            ];
        }

        try {
            $client = new DeepSeekClient(
                $apiKey,
                (string) ($this->settings['aiBaseUrl'] ?? 'https://api.deepseek.com'),
                (string) ($this->settings['aiModel'] ?? 'deepseek-chat')
            );
            $reply = $client->chat(
                $messages,
                (float) ($this->settings['aiTemperature'] ?? 0.8),
                max(80, min(600, (int) ($this->settings['aiMaxTokens'] ?? 180)))
            );
            $reply = mb_substr(trim($reply), 0, 1200, 'UTF-8');
            $history[] = ['role' => 'assistant', 'content' => $reply];
            $this->store->save($key, array_slice($history, -$historyLimit));
            $this->reply($channelId, $userId, $reply);
        } catch (Throwable $exception) {
            self::log('DeepSeek 对话失败', [
                'user_id' => $userId,
                'channel_id' => $channelId,
                'error' => $exception->getMessage(),
            ]);
            $this->reply($channelId, $userId, '这次没有想好怎么回答：' . $exception->getMessage());
        }
    }

    /**
     * Admin-side connectivity test.
     */
    public function test(string $prompt = '你好，用一句话确认你已就绪。'): string
    {
        $apiKey = trim((string) ($this->settings['aiApiKey'] ?? ''));

        if ('' === $apiKey) {
            throw new \RuntimeException('请先填写 DeepSeek API Key');
        }

        $client = new DeepSeekClient(
            $apiKey,
            (string) ($this->settings['aiBaseUrl'] ?? 'https://api.deepseek.com'),
            (string) ($this->settings['aiModel'] ?? 'deepseek-chat')
        );

        return $client->chat(
            [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $prompt],
            ],
            (float) ($this->settings['aiTemperature'] ?? 0.8),
            120
        );
    }

    private function systemPrompt(): string
    {
        $prompt = trim((string) ($this->settings['aiSystemPrompt'] ?? ''));

        if ('' === $prompt) {
            $prompt = '你是一个中文角色扮演助手。保持角色一致，语气自然，回答简短。';
        }

        if (mb_strlen($prompt, 'UTF-8') > self::MAX_PROMPT_CHARS) {
            $prompt = mb_substr($prompt, 0, self::MAX_PROMPT_CHARS, 'UTF-8');
        }

        return $prompt . "\n\n[输出要求] 使用 KMarkdown：动作、神态用 *斜体*，对话可以直接书写；不要 HTML，不要代码块包裹全文，回答控制在 200 字以内。";
    }

    private function isBotMentioned(
        string $content,
        array $event,
        array $extra,
        string $botId,
        string $botName
    ): bool {
        if (false !== strpos($content, '(met)' . $botId . '(met)')) {
            return true;
        }

        $mentions = array_merge(
            is_array($extra['mention'] ?? null) ? $extra['mention'] : [],
            is_array($event['mention'] ?? null) ? $event['mention'] : [],
            is_array($extra['mentions'] ?? null) ? $extra['mentions'] : []
        );

        foreach ($mentions as $mention) {
            $id = is_array($mention) ? (string) ($mention['id'] ?? '') : (string) $mention;

            if ($id === $botId) {
                return true;
            }
        }

        // Some KOOK gateways do not populate mention[] for bot mentions.
        // Only an explicit @BotName is accepted; a random @user must not
        // trigger the AI.
        if ('' !== $botName) {
            foreach (['@' . $botName, '@' . $botName . '#'] as $needle) {
                if ('' !== $needle && false !== strpos($content, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cleanMention(string $content, string $botId, string $botName = ''): string
    {
        $text = str_replace('(met)' . $botId . '(met)', ' ', $content);
        $text = preg_replace('/\(met\)\d+\(met\)/u', ' ', $text) ?? $text;

        if ('' !== $botName) {
            $text = str_replace(['@' . $botName, $botName], ' ', $text);
        }

        $text = preg_replace('/@\S+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function reply(string $channelId, string $userId, string $text): void
    {
        $plain = '(met)' . $userId . '(met) ' . $text;

        try {
            $this->client->sendChannelText($channelId, $plain);
        } catch (Throwable $exception) {
            try {
                $this->client->sendDirectText($userId, $text);
            } catch (Throwable $directException) {
                self::log('AI 回复发送失败', [
                    'channel_id' => $channelId,
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                    'direct_error' => $directException->getMessage(),
                ]);
            }
        }
    }

    public static function tailLogs(int $lines = 80): array
    {
        $path = self::logPath();

        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path, false, null, max(0, (int) @filesize($path) - 30000));
        $rows = preg_split('/\R/u', trim((string) $raw)) ?: [];

        return array_slice($rows, -max(1, min(200, $lines)));
    }

    private static function logPath(): string
    {
        return sys_get_temp_dir() . '/moepanel-ai-chat.log';
    }

    private static function log(string $message, array $context = []): void
    {
        $path = self::logPath();

        @file_put_contents(
            $path,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
        @chmod($path, 0666);
    }
}
