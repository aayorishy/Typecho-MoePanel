<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * 新成员加入欢迎：发送纯文字 KMarkdown。
 */
class Welcome
{
    public static function handleEvent(array $message, array $settings): void
    {
        $data = is_array($message['d'] ?? null) ? $message['d'] : [];
        $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $type = (string) ($extra['type'] ?? '');

        if (!in_array($type, ['joined_guild', 'joined_guild_notice'], true)) {
            return;
        }

        $settings = Settings::reload();
        $body = is_array($extra['body'] ?? null) ? $extra['body'] : [];
        $user = is_array($body['user'] ?? null) ? $body['user'] : [];
        $userId = trim((string) ($body['user_id'] ?? ''));

        if ('' === $userId) {
            $userId = trim((string) ($user['id'] ?? ''));
        }

        if ('' === $userId) {
            $userId = trim((string) ($data['author_id'] ?? ''));
        }

        $guildId = (string) ($data['target_id'] ?? ($extra['guild_id'] ?? ($settings['guildId'] ?? '')));

        try {
            self::send($settings, $userId, $guildId, $user);
        } catch (\Throwable $error) {
            self::log('欢迎发送失败', [
                'user_id' => $userId,
                'guild_id' => $guildId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function send(array $settings, string $userId, string $guildId = '', array $user = []): array
    {
        $token = trim((string) ($settings['botToken'] ?? ''));
        $channelId = trim((string) ($settings['welcomeChannelId'] ?? ''));

        if ('' === $token) {
            throw new KookException('机器人 Token 未配置');
        }

        if ('' === $channelId) {
            throw new KookException('未设置欢迎频道');
        }

        if ('' === $userId) {
            throw new KookException('欢迎用户 ID 为空');
        }

        $client = new KookClient($token);
        $client->setTimeout(10);
        $userName = trim((string) ($user['username'] ?? ''));
        $identify = trim((string) ($user['identify_num'] ?? ''));
        $memberCount = 0;

        try {
            $view = $client->userView($userId, $guildId);
            $userName = '' !== $userName ? $userName : (string) ($view['username'] ?? '');
            $identify = '' !== $identify ? $identify : (string) ($view['identify_num'] ?? '');
        } catch (\Throwable $ignored) {
        }

        if ('' !== $guildId) {
            try {
                $memberCount = $client->guildMemberCount($guildId);
            } catch (\Throwable $ignored) {
            }
        }

        $displayName = trim($userName . ('' !== $identify ? '#' . $identify : ''));
        $displayName = '' !== $displayName ? $displayName : '新成员';
        $site = trim((string) ($settings['siteTitle'] ?? '本站'));
        $site = '' !== $site ? $site : '本站';
        $template = trim((string) ($settings['welcomeGreeting'] ?? ''));

        if ('' === $template) {
            $template = "欢迎 {user} 宝宝 ๑•́ㅅ•̀๑\n\n( 欢迎小宝 )\n( 玩的开心 )";
        }

        $text = str_replace(
            ['{user}', '{username}', '{site}', '{count}'],
            ['(met)' . $userId . '(met)', $displayName, $site, (string) ($memberCount > 0 ? $memberCount : 1)],
            $template
        );
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);

        if ('' === $text) {
            throw new KookException('欢迎文字为空');
        }

        self::log('发送文字欢迎', [
            'channel_id' => $channelId,
            'user_id' => $userId,
            'guild_id' => $guildId,
            'member_count' => $memberCount,
            'text' => mb_substr($text, 0, 300, 'UTF-8'),
        ]);

        return $client->sendChannelText($channelId, $text);
    }

    private static function log(string $message, array $context = []): void
    {
        $path = sys_get_temp_dir() . '/moepanel-welcome.log';
        @file_put_contents(
            $path,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
        @chmod($path, 0666);
    }
}
