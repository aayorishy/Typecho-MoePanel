<?php

namespace TypechoPlugin\MoePanel\Kook;

use Typecho\Db;
use Widget\Options;
use Widget\User;

/**
 * 验证码流程、KOOK 身份映射与登录提交。
 */
class Auth
{
    private static bool $flowTableReady = false;

    public static function client(array $settings): KookClient
    {
        return new KookClient((string) ($settings['botToken'] ?? ''));
    }

    /**
     * 解析身份 -> 检查映射 -> 限流 -> 生成验证码 -> 发送卡片私信。
     *
     * @return array{ok:bool,error?:string,step?:string,flow?:string,masked?:string,expires?:int,ttl?:int}
     */
    public static function startVerification(array $settings, string $identity, string $ip): array
    {
        $client = self::client($settings);

        try {
            $user = $client->resolveIdentity($identity, (string) ($settings['guildId'] ?? ''));
        } catch (KookException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'step' => 'identity'];
        }

        $kookId = (string) $user['id'];
        $uid = self::uidFor($settings, $kookId);

        if (null === $uid || !self::userExists($uid)) {
            return ['ok' => false, 'error' => '该 KOOK 账号未绑定 Typecho 用户', 'step' => 'identity'];
        }

        self::saveProfile($user, $uid);
        $db = Db::get();

        try {
            self::ensureTable();
            self::cleanup($db);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '验证码服务暂时不可用，请稍后再试', 'step' => 'identity'];
        }

        $now = time();
        $resend = max(15, (int) ($settings['resendInterval'] ?? 45));

        if (self::countRows($db, 'kook_id', $kookId, $now - $resend) > 0) {
            return [
                'ok' => false,
                'error' => sprintf('发送过于频繁，请 %d 秒后再试', $resend),
                'step' => 'identity',
            ];
        }

        if (self::countRows($db, 'ip', $ip, $now - 3600) >= 8) {
            return ['ok' => false, 'error' => '当前 IP 请求过于频繁，请一小时后再试', 'step' => 'identity'];
        }

        $ttl = max(120, min(1800, (int) ($settings['codeTtl'] ?? 600)));
        $flow = bin2hex(random_bytes(16));
        $code = (string) random_int(100000, 999999);
        $display = self::displayIdentity($user);

        try {
            $db->query(
                $db->insert('table.' . Settings::FLOW_TABLE)->rows([
                    'flow' => $flow,
                    'kook_id' => $kookId,
                    'nickname' => self::limit($display, 128),
                    'code_hash' => self::codeHash($flow, $code),
                    'expires' => $now + $ttl,
                    'attempts' => 0,
                    'created' => $now,
                    'ip' => self::limit($ip, 64),
                ])
            );
        } catch (\Throwable $e) {
            try {
                Settings::createFlowTableIfNeeded();
                $db->query(
                    $db->insert('table.' . Settings::FLOW_TABLE)->rows([
                        'flow' => $flow,
                        'kook_id' => $kookId,
                        'nickname' => self::limit($display, 128),
                        'code_hash' => self::codeHash($flow, $code),
                        'expires' => $now + $ttl,
                        'attempts' => 0,
                        'created' => $now,
                        'ip' => self::limit($ip, 64),
                    ])
                );
            } catch (\Throwable $e2) {
                return ['ok' => false, 'error' => '验证码服务暂时不可用，请稍后再试', 'step' => 'identity'];
            }
        }

        $minutes = max(1, (int) round($ttl / 60));
        $vars = [
            '{code}' => $code,
            '{minutes}' => (string) $minutes,
            '{ip}' => $ip,
            '{time}' => date('Y-m-d H:i:s'),
            '{site}' => (string) Options::alloc()->title,
        ];

        try {
            self::sendConfiguredCard(
                $client,
                $kookId,
                $settings,
                'verifyCardJson',
                (string) ($settings['verifyCardTitle'] ?? 'KOOK 登录验证'),
                (string) ($settings['verifyCardContent'] ?? '**{code}**'),
                (string) ($settings['verifyCardContext'] ?? ''),
                $vars
            );
        } catch (KookException $e) {
            self::deleteFlow($db, $flow);
            return ['ok' => false, 'error' => '验证码发送失败：' . $e->getMessage(), 'step' => 'identity'];
        } catch (\Throwable $e) {
            self::deleteFlow($db, $flow);
            return ['ok' => false, 'error' => '验证码发送失败，请稍后再试', 'step' => 'identity'];
        }

        return [
            'ok' => true,
            'flow' => $flow,
            'masked' => self::maskIdentity($display),
            'expires' => $now + $ttl,
            'ttl' => $ttl,
        ];
    }

    /**
     * 校验验证码。
     *
     * @return array{ok:bool,error?:string,step?:string,uid?:int,kook_id?:string}
     */
    public static function verify(array $settings, string $flow, string $code, string $ip): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $flow)) {
            return ['ok' => false, 'error' => '验证码已失效，请重新获取', 'step' => 'identity'];
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return ['ok' => false, 'error' => '请输入 6 位数字验证码', 'step' => 'code'];
        }

        $db = Db::get();

        try {
            self::ensureTable();
            self::cleanup($db);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '验证码服务暂时不可用，请稍后再试', 'step' => 'identity'];
        }

        $row = $db->fetchRow(
            $db->select()
                ->from('table.' . Settings::FLOW_TABLE)
                ->where('flow = ?', $flow)
                ->limit(1)
        );

        if (empty($row)) {
            return ['ok' => false, 'error' => '验证码已失效，请重新获取', 'step' => 'identity'];
        }

        if ((int) $row['expires'] < time()) {
            self::deleteFlow($db, $flow);
            return ['ok' => false, 'error' => '验证码已过期，请重新获取', 'step' => 'identity'];
        }

        $maxAttempts = max(3, min(10, (int) ($settings['maxAttempts'] ?? 5)));

        if ((int) $row['attempts'] >= $maxAttempts) {
            self::deleteFlow($db, $flow);
            return ['ok' => false, 'error' => '尝试次数过多，请重新获取验证码', 'step' => 'identity'];
        }

        $hash = self::codeHash($flow, $code);

        if (!hash_equals((string) $row['code_hash'], $hash)) {
            $attempts = (int) $row['attempts'] + 1;

            $db->query(
                $db->update('table.' . Settings::FLOW_TABLE)
                    ->rows(['attempts' => $attempts])
                    ->where('flow = ?', $flow)
            );

            if ($attempts >= $maxAttempts) {
                self::deleteFlow($db, $flow);
                return ['ok' => false, 'error' => '尝试次数过多，请重新获取验证码', 'step' => 'identity'];
            }

            return [
                'ok' => false,
                'error' => sprintf('验证码不正确，还可尝试 %d 次', $maxAttempts - $attempts),
                'step' => 'code',
                'flow' => $flow,
            ];
        }

        $kookId = (string) $row['kook_id'];
        $uid = self::uidFor($settings, $kookId);

        if (null === $uid || !self::userExists($uid)) {
            self::deleteFlow($db, $flow);
            return ['ok' => false, 'error' => '该 KOOK 账号未绑定 Typecho 用户', 'step' => 'identity'];
        }

        self::deleteFlow($db, $flow);

        return ['ok' => true, 'uid' => $uid, 'kook_id' => $kookId];
    }

    /**
     * 完成 Typecho 登录（提交 cookie），随后按需发送成功通知卡片。
     */
    public static function completeLogin(int $uid, string $kookId, array $settings, string $ip): bool
    {
        $user = User::alloc();

        if (!$user->simpleLogin($uid, false, 0)) {
            return false;
        }

        self::linkProfile($uid, $kookId);

        if ('1' === (string) ($settings['syncDisplayName'] ?? '1')) {
            $profile = self::profileForUid($uid);
            $nickname = is_array($profile) ? trim((string) ($profile['nickname'] ?? '')) : '';

            if ('' !== $nickname) {
                try {
                    $db = Db::get();
                    $db->query(
                        $db->update('table.users')
                            ->rows(['screenName' => self::limit($nickname, 128)])
                            ->where('uid = ?', $uid)
                    );
                } catch (\Throwable $e) {
                    // 显示名同步失败不影响登录。
                }
            }
        }

        if (
            '1' === (string) ($settings['successCard'] ?? '1')
            && '' !== trim((string) ($settings['botToken'] ?? ''))
            && '' !== $kookId
        ) {
            try {
                $siteTitle = (string) Options::alloc()->title;
                $vars = [
                    '{site}' => $siteTitle,
                    '{ip}' => $ip,
                    '{time}' => date('Y-m-d H:i:s'),
                ];
                $client = self::client($settings);
                self::sendConfiguredCard(
                    $client,
                    $kookId,
                    $settings,
                    'successCardJson',
                    (string) ($settings['successCardTitle'] ?? '登录成功'),
                    (string) ($settings['successCardContent'] ?? '你已成功登录 **{site}**。'),
                    (string) ($settings['successCardContext'] ?? ''),
                    $vars
                );
            } catch (\Throwable $e) {
                // 通知失败不能影响登录。
            }
        }

        return true;
    }

    /**
     * 只保留所有者映射：ownerKookId -> ownerUid。
     */
    public static function mapping(array $settings): array
    {
        $map = [];
        $ownerId = trim((string) ($settings['ownerKookId'] ?? ''));
        $ownerUid = (int) ($settings['ownerUid'] ?? 1);

        if ('' !== $ownerId && preg_match('/^\d{3,32}$/', $ownerId) && $ownerUid > 0) {
            $map[$ownerId] = $ownerUid;
        }

        return $map;
    }

    public static function uidFor(array $settings, string $kookId): ?int
    {
        $map = self::mapping($settings);
        return isset($map[$kookId]) ? (int) $map[$kookId] : null;
    }

    /**
     * 缓存 KOOK 头像/昵称，后台侧边栏与概要页优先使用。
     */
    public static function saveProfile(array $user, int $uid = 0): void
    {
        $kookId = trim((string) ($user['id'] ?? ''));

        if ('' === $kookId) {
            return;
        }

        try {
            Settings::createProfileTableIfNeeded();
            $db = Db::get();
            $rows = [
                'uid' => $uid,
                'username' => self::limit((string) ($user['username'] ?? ''), 128),
                'identify_num' => self::limit((string) ($user['identify_num'] ?? ''), 32),
                'nickname' => self::limit((string) ($user['nickname'] ?? ''), 128),
                'avatar' => self::limit((string) ($user['avatar'] ?? ''), 500),
                'updated' => time(),
            ];
            $affected = $db->query(
                $db->update('table.' . Settings::PROFILE_TABLE)
                    ->rows($rows)
                    ->where('kook_id = ?', $kookId)
            );

            if (!$affected) {
                try {
                    $db->query(
                        $db->insert('table.' . Settings::PROFILE_TABLE)
                            ->rows(array_merge(['kook_id' => $kookId], $rows))
                    );
                } catch (\Throwable $e) {
                    $db->query(
                        $db->update('table.' . Settings::PROFILE_TABLE)
                            ->rows($rows)
                            ->where('kook_id = ?', $kookId)
                    );
                }
            }
        } catch (\Throwable $e) {
            // 缓存失败不影响登录。
        }
    }

    public static function linkProfile(int $uid, string $kookId): void
    {
        if ($uid <= 0 || '' === $kookId) {
            return;
        }

        try {
            $db = Db::get();
            $db->query(
                $db->update('table.' . Settings::PROFILE_TABLE)
                    ->rows(['uid' => $uid, 'updated' => time()])
                    ->where('kook_id = ?', $kookId)
            );
        } catch (\Throwable $e) {
            // 忽略。
        }
    }

    public static function profileForUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        try {
            Settings::createProfileTableIfNeeded();
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select()
                    ->from('table.' . Settings::PROFILE_TABLE)
                    ->where('uid = ?', $uid)
                    ->order('updated', Db::SORT_DESC)
                    ->limit(1)
            );

            return !empty($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function userExists(int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }

        $db = Db::get();
        $row = $db->fetchRow(
            $db->select('uid')
                ->from('table.users')
                ->where('uid = ?', $uid)
                ->limit(1)
        );

        return !empty($row);
    }

    public static function ensureTable(): void
    {
        if (self::$flowTableReady) {
            return;
        }

        Settings::createFlowTableIfNeeded();
        Settings::createProfileTableIfNeeded();
        self::$flowTableReady = true;
    }

    public static function displayIdentity(array $user): string
    {
        $username = trim((string) ($user['username'] ?? ''));
        $identify = trim((string) ($user['identify_num'] ?? ''));

        if ('' !== $username && '' !== $identify) {
            return $username . '#' . $identify;
        }

        $nickname = trim((string) ($user['nickname'] ?? ''));

        if ('' !== $nickname) {
            return $nickname;
        }

        return (string) ($user['id'] ?? 'KOOK 用户');
    }

    public static function maskIdentity(string $identity): string
    {
        if (preg_match('/^(.+)#(\d+)$/u', $identity, $m)) {
            return self::maskName($m[1]) . '#' . $m[2];
        }

        return self::maskName($identity);
    }

    /**
     * 根据 flow 取回脱敏后的账号，用于验证码步骤的提示条。
     */
    public static function maskedForFlow(string $flow): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $flow)) {
            return '该 KOOK 账号';
        }

        try {
            self::ensureTable();
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('nickname')
                    ->from('table.' . Settings::FLOW_TABLE)
                    ->where('flow = ?', $flow)
                    ->limit(1)
            );

            if (!empty($row['nickname'])) {
                return self::maskIdentity((string) $row['nickname']);
            }
        } catch (\Throwable $e) {
            // 回退到通用文案。
        }

        return '该 KOOK 账号';
    }

    private static function maskName(string $name): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($length <= 1) {
            return $name . '***';
        }

        if ($length <= 2) {
            return self::substr($name, 0, 1) . '***';
        }

        return self::substr($name, 0, 2) . '***' . self::substr($name, -1, 1);
    }

    private static function codeHash(string $flow, string $code): string
    {
        $secret = (string) Options::alloc()->secret;

        if ('' === $secret) {
            $secret = 'kookauth';
        }

        return hash('sha256', $flow . ':' . $code . ':' . $secret);
    }

    private static function countRows(Db $db, string $column, string $value, int $since): int
    {
        $allowed = ['kook_id', 'ip'];

        if (!in_array($column, $allowed, true)) {
            return 0;
        }

        $row = $db->fetchRow(
            $db->select(['COUNT(*)' => 'num'])
                ->from('table.' . Settings::FLOW_TABLE)
                ->where($column . ' = ?', $value)
                ->where('created >= ?', $since)
        );

        return (int) ($row['num'] ?? 0);
    }

    private static function cleanup(Db $db): void
    {
        $now = time();

        try {
            $db->query(
                $db->delete('table.' . Settings::FLOW_TABLE)
                    ->where('expires < ?', $now - 900)
                    ->orWhere('created < ?', $now - 86400)
            );
        } catch (\Throwable $e) {
            // 清理失败不影响主流程。
        }
    }

    private static function deleteFlow(Db $db, string $flow): void
    {
        try {
            $db->query(
                $db->delete('table.' . Settings::FLOW_TABLE)->where('flow = ?', $flow)
            );
        } catch (\Throwable $e) {
            // 忽略。
        }
    }

    private static function substr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        return substr($value, $start, $length);
    }

    private static function renderCardText(string $template, array $vars, bool $kmarkdown): string
    {
        if ($kmarkdown) {
            $vars['{site}'] = KookClient::escapeKmarkdown((string) ($vars['{site}'] ?? ''));
            $vars['{ip}'] = KookClient::escapeKmarkdown((string) ($vars['{ip}'] ?? ''));
        }

        return strtr($template, $vars);
    }

    private static function sendConfiguredCard(
        KookClient $client,
        string $kookId,
        array $settings,
        string $jsonKey,
        string $title,
        string $body,
        string $context,
        array $vars
    ): void {
        $raw = trim((string) ($settings[$jsonKey] ?? ''));

        if ('' !== $raw) {
            $raw = strtr($raw, $vars);
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $card = isset($decoded['type']) ? $decoded : (isset($decoded[0]) ? $decoded[0] : null);

                if (is_array($card) && 'card' === ($card['type'] ?? '')) {
                    $client->sendDirectCardPayload($kookId, $card);
                    return;
                }
            }
        }

        $client->sendCard(
            $kookId,
            (string) ($settings['cardColor'] ?? KookClient::DEFAULT_CARD_COLOR),
            self::renderCardText($title, $vars, false),
            self::renderCardText($body, $vars, true),
            self::contextLines($context, $vars)
        );
    }

    private static function contextLines(string $template, array $vars): array
    {
        if ('' === trim($template)) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\R/', $template) as $line) {
            $line = trim(self::renderCardText($line, $vars, false));

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return array_slice($lines, 0, 8);
    }

    private static function limit(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }
}
