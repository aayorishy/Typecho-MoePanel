<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * KOOK 身份组领取：发布按钮卡片，并处理按钮点击事件。
 */
class RoleClaim
{
    public static function parseRoles(string $raw): array
    {
        $roles = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 2 || !preg_match('/^\d{2,32}$/', $parts[0])) {
                continue;
            }

            $theme = $parts[2] ?? 'primary';

            if (!in_array($theme, ['primary', 'success', 'danger', 'warning', 'info', 'secondary'], true)) {
                $theme = 'primary';
            }

            $name = trim($parts[1]);
            $roles[] = [
                'id' => $parts[0],
                'name' => '' !== $name ? mb_substr($name, 0, 20, 'UTF-8') : ('角色 ' . $parts[0]),
                'theme' => $theme,
            ];
        }

        return $roles;
    }

    public static function card(array $roles, string $color): array
    {
        $modules = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain-text', 'content' => '✦ 身份组领取 ✦'],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'kmarkdown', 'content' => '✦✦✦  **点亮你的专属昵称颜色**'],
            ],
        ];

        foreach ($roles as $role) {
            $modules[] = [
                'type' => 'action-group',
                'elements' => [
                    [
                        'type' => 'button',
                        'theme' => $role['theme'],
                        'value' => 'role:' . $role['id'],
                        'click' => 'return-val',
                        'text' => ['type' => 'plain-text', 'content' => $role['name']],
                    ],
                    [
                        'type' => 'button',
                        'theme' => 'secondary',
                        'value' => 'role-off:' . $role['id'],
                        'click' => 'return-val',
                        'text' => ['type' => 'plain-text', 'content' => '移除'],
                    ],
                ],
            ];
        }

        $modules[] = [
            'type' => 'context',
            'elements' => [
                ['type' => 'plain-text', 'content' => '点击角色名领取 · 点击移除取消 · 修改会立即生效'],
            ],
        ];

        return [
            'type' => 'card',
            'color' => KookClient::normalizeColor($color),
            'size' => 'lg',
            'modules' => $modules,
        ];
    }

    public static function publish(array $settings): array
    {
        $channelId = trim((string) ($settings['claimChannelId'] ?? ''));
        $token = trim((string) ($settings['botToken'] ?? ''));
        $roles = self::parseRoles((string) ($settings['claimRoles'] ?? ''));

        if ('' === $channelId || '' === $token) {
            throw new KookException('请先填写身份组频道和机器人 Token');
        }

        if ([] === $roles) {
            throw new KookException('没有可领取的身份组，请按“角色ID|显示名|主题色”填写');
        }

        $client = new KookClient($token);
        $client->setTimeout(8);

        return $client->sendChannelCardPayload(
            $channelId,
            self::card($roles, (string) ($settings['cardColor'] ?? KookClient::DEFAULT_CARD_COLOR))
        );
    }

    public static function handleEvent(array $message, array $settings): void
    {
        $data = $message['d'] ?? null;

        if (!is_array($data)) {
            return;
        }

        $extra = $data['extra'] ?? null;

        if (!is_array($extra)) {
            return;
        }

        $body = $extra['body'] ?? null;

        if (!is_array($body)) {
            return;
        }

        $value = trim((string) ($body['value'] ?? ''));

        $roleId = '';
        $revoke = false;

        if (preg_match('~^role:(\d{2,32})$~', $value, $matches)) {
            $roleId = $matches[1];
        } elseif (preg_match('~^role-off:(\d{2,32})$~', $value, $matches)) {
            $roleId = $matches[1];
            $revoke = true;
        } elseif (preg_match('~^role_(claim|revoke):(\d{2,32})$~', $value, $matches)) {
            $roleId = $matches[2];
            $revoke = 'revoke' === $matches[1];
        }

        if ('' === $roleId) {
            self::log('忽略按钮 value=' . $value . ' event=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        $userId = trim((string) ($body['user_id'] ?? ''));

        if ('' === $userId) {
            $userId = trim((string) ($body['user']['id'] ?? ''));
        }

        if ('' === $userId) {
            $userId = trim((string) ($extra['user_id'] ?? $data['author_id'] ?? ''));
        }

        $guildId = trim((string) ($extra['guild_id'] ?? ''));

        if ('' === $guildId) {
            $guildId = trim((string) ($body['guild_id'] ?? $settings['guildId'] ?? ''));
        }
        $token = trim((string) ($settings['botToken'] ?? ''));

        self::log('按钮 ' . ($revoke ? '移除' : '领取') . ' value=' . $value
            . ' user=' . $userId . ' guild=' . $guildId
            . ' msg=' . (string) ($data['msg_id'] ?? ''));

        if ('' === $userId || '' === $guildId || '' === $token) {
            self::log('身份组操作中止：缺少 user/guild/token');
            return;
        }

        $client = new KookClient($token);
        $client->setTimeout(8);

        try {
            $existing = $client->guildMemberRoles($guildId, $userId);

            if ([] !== $existing) {
                $owned = in_array($roleId, $existing, true);

                if (!$revoke && $owned) {
                    self::notify($client, $userId, '你已经拥有这个身份组。');
                    self::log('身份组已存在 role=' . $roleId);
                    return;
                }

                if ($revoke && !$owned) {
                    self::notify($client, $userId, '你还没有这个身份组。');
                    self::log('身份组未拥有 role=' . $roleId);
                    return;
                }
            }

            $guildRoles = $client->guildRoleList($guildId);
            $target = null;

            foreach ($guildRoles as $guildRole) {
                if (is_array($guildRole) && (string) ($guildRole['role_id'] ?? $guildRole['id'] ?? '') === $roleId) {
                    $target = $guildRole;
                    break;
                }
            }

            if (null === $target) {
                throw new KookException('身份组不存在，或机器人无权读取服务器身份组');
            }

            $result = $revoke
                ? $client->revokeRole($guildId, $userId, $roleId)
                : $client->grantRole($guildId, $userId, $roleId);

            // KOOK returns success for the button callback even when the role
            // grant was rejected, so verify the member's role list as well.
            sleep(1);
            $roles = $client->guildMemberRoles($guildId, $userId);

            if ([] !== $roles) {
                $hasRole = in_array($roleId, $roles, true);

                if (!$revoke && !$hasRole) {
                    throw new KookException('KOOK 未实际授予身份组，请检查机器人身份组排序和「管理角色」权限');
                }

                if ($revoke && $hasRole) {
                    throw new KookException('KOOK 未实际移除身份组，请检查机器人身份组排序和「管理角色」权限');
                }
            }

            self::log('身份组操作成功 ' . ($revoke ? 'revoke' : 'grant')
                . ' role=' . $roleId . ' roles=' . json_encode($roles)
                . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $error) {
            self::log('身份组操作失败 role=' . $roleId . '：' . $error->getMessage());

            try {
                $client->sendDirectText($userId, '身份组操作失败：' . $error->getMessage());
            } catch (\Throwable $ignored) {
            }
        }
    }

    private static function notify(KookClient $client, string $userId, string $text): void
    {
        try {
            $card = CardMessage::notice('身份组领取', $text, 'MoePanel · ROLE CLAIM')[0];
            $client->sendDirectCardPayload($userId, $card);
        } catch (\Throwable $ignored) {
            try {
                $client->sendDirectText($userId, $text);
            } catch (\Throwable $ignored) {
            }
        }
    }

    private static function log(string $message): void
    {
        $path = sys_get_temp_dir() . '/moepanel-role-claim.log';
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
        @chmod($path, 0666);
    }
}
