<?php

namespace TypechoPlugin\MoePanel\Kook;

use Typecho\Db;
use Typecho\Plugin\Exception as PluginException;

/**
 * KOOK 登录设置存储。
 *
 * Typecho 在禁用插件时会删除插件配置选项 选项，因此本插件把真正的
 * 设置保存在独立的数据表 `{prefix}kookauth_settings` 中。`plugin:MoePanel`
 * 只是与 Typecho 标准插件配置页面兼容的镜像，两者在后台请求中自动同步。
 */
class Settings
{
    public const VERSION = '1.0.0';
    public const STORE_TABLE = 'kookauth_settings';
    public const FLOW_TABLE = 'kookauth_flows';
    public const PROFILE_TABLE = 'kookauth_profiles';
    public const OPTION_NAME = 'plugin:MoePanel';
    public const LEGACY_OPTION_NAME = 'plugin:KookAuth';
    public const STORE_KEY = 'settings';

    private static ?array $cache = null;
    private static bool $tableReady = false;

    /**
     * 所有可配置项的默认值。
     */
    public static function defaults(): array
    {
        return [
            'enable' => '0',
            'oauthLogin' => '1',
            'codeLogin' => '1',
            'passwordFallback' => '1',
            'successCard' => '1',
            'syncDisplayName' => '1',
            'clientId' => '',
            'clientSecret' => '',
            'botToken' => '',
            'guildId' => '',
            'ownerKookId' => '',
            'ownerUid' => '1',
            'cardColor' => '#FFCDCC',
            'verifyCardTitle' => 'KOOK 登录验证',
            'verifyCardContent' => "**{code}**\n\n验证码 **{minutes} 分钟**内有效，请勿转发给他人。",
            'verifyCardContext' => "请求 IP：{ip}\n时间：{time}",
            'successCardTitle' => '登录成功',
            'successCardContent' => "你已成功登录 **{site}**。\n\n时间：{time}\nIP：{ip}",
            'successCardContext' => '如果这不是你的操作，请立即检查 MoePanel 的 KOOK 绑定设置。',
            'verifyCardJson' => '',
            'successCardJson' => '',
            'codeTtl' => '600',
            'resendInterval' => '45',
            'maxAttempts' => '5',
            'pageTitle' => '登录 KOOK',
            'pageSubtitle' => '输入昵称#编号 或 用户 ID，验证码会发送到你的 KOOK 私信。',
            'logoUrl' => '',
            'logoDarkUrl' => '',
            'accentColor' => '#5b5bd6',
            'accentColor2' => '#7c6cff',
            'uploadChannelId' => '',
            'githubSecret' => '',
            'githubApiToken' => '',
            'githubChannelId' => '',
            'githubCardNote' => '',
            'welcomeChannelId' => '',
            'welcomeGreeting' => 'Hey {user} 欢迎来到 {site}，愿你在这里玩得开心。',
            'claimChannelId' => '',
            'claimRoles' => '',
            'postChannelId' => '6161751426312745',
            'postCardTitle' => '新文章 · {title}',
            'postCardBody' => "{summary}\n\n[阅读全文]({url})",
            'aiEnabled' => '0',
            'aiApiKey' => '',
            'aiBaseUrl' => 'https://api.deepseek.com',
            'aiModel' => 'deepseek-chat',
            'aiSystemPrompt' => "你是「小宅」，一个温柔、有点害羞的中文角色扮演助手。\n"
                . "说话自然简短，会使用动作和神态描写。\n"
                . "不要自称 AI，不要解释设定，不要输出代码块。",
            'aiTemperature' => '0.8',
            'aiMaxTokens' => '180',
            'aiHistoryLimit' => '6',
            'aiChannelId' => '',
            'aiReplyAll' => '0',
        ];
    }

    /**
     * 表单字段名（用于自动保存白名单）。
     */
    public static function fieldNames(): array
    {
        return array_keys(self::defaults());
    }

    public static function get(string $key, ?string $default = null): string
    {
        $settings = self::load();
        return array_key_exists($key, $settings) ? (string) $settings[$key] : (string) $default;
    }

    /**
     * 读取完整设置（持久化表 -> 插件选项 -> 默认值）。
     */
    public static function reload(): array
    {
        self::$cache = null;
        return self::load();
    }

    public static function load(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $stored = self::readStoreSafe();

        if (null === $stored) {
            $option = self::readOption();
            if (null !== $option) {
                $stored = $option;
                try {
                    self::writeStore($option);
                } catch (\Throwable $e) {
                    // 只读环境：继续使用选项值。
                }
            }
        }

        self::$cache = self::normalize($stored ?? self::defaults());
        return self::$cache;
    }

    /**
     * 规范化任意来源的数据，返回完整的设置数组（字符串值）。
     *
     * @param mixed $raw
     * @param bool $strict 严格模式下收集人类可读的错误。
     * @param array $errors
     */
    public static function normalize($raw, bool $strict = false, array &$errors = []): array
    {
        $raw = is_array($raw) ? $raw : [];
        $defaults = self::defaults();
        $out = $defaults;

        foreach (['enable', 'oauthLogin', 'codeLogin', 'passwordFallback', 'successCard', 'syncDisplayName'] as $flag) {
            $out[$flag] = self::flag($raw[$flag] ?? $defaults[$flag]);
        }

        $out['clientId'] = self::plain($raw['clientId'] ?? '', 200);
        $out['clientSecret'] = self::secret($raw['clientSecret'] ?? '');
        $out['botToken'] = self::secret($raw['botToken'] ?? '');
        $out['guildId'] = self::plain($raw['guildId'] ?? '', 40);
        $out['ownerKookId'] = self::plain($raw['ownerKookId'] ?? '', 40);
        $out['ownerUid'] = self::intRange($raw['ownerUid'] ?? 1, 1, 2147483647, 1);
        $out['cardColor'] = self::color($raw['cardColor'] ?? '');
        $out['verifyCardTitle'] = self::plain($raw['verifyCardTitle'] ?? $defaults['verifyCardTitle'], 100)
            ?: $defaults['verifyCardTitle'];
        $out['verifyCardContent'] = self::multiline(
            $raw['verifyCardContent'] ?? $defaults['verifyCardContent'],
            5000,
            $defaults['verifyCardContent']
        );
        $out['verifyCardContext'] = self::multiline($raw['verifyCardContext'] ?? '', 1200, '');
        $out['successCardTitle'] = self::plain($raw['successCardTitle'] ?? $defaults['successCardTitle'], 100)
            ?: $defaults['successCardTitle'];
        $out['successCardContent'] = self::multiline(
            $raw['successCardContent'] ?? $defaults['successCardContent'],
            5000,
            $defaults['successCardContent']
        );
        $out['successCardContext'] = self::multiline($raw['successCardContext'] ?? '', 1200, '');
        $out['verifyCardJson'] = mb_substr(trim((string) ($raw['verifyCardJson'] ?? '')), 0, 12000, 'UTF-8');
        $out['successCardJson'] = mb_substr(trim((string) ($raw['successCardJson'] ?? '')), 0, 12000, 'UTF-8');
        $out['codeTtl'] = self::intRange($raw['codeTtl'] ?? 600, 120, 1800, 600);
        $out['resendInterval'] = self::intRange($raw['resendInterval'] ?? 45, 15, 600, 45);
        $out['maxAttempts'] = self::intRange($raw['maxAttempts'] ?? 5, 3, 10, 5);
        $out['pageTitle'] = self::plain($raw['pageTitle'] ?? $defaults['pageTitle'], 60) ?: $defaults['pageTitle'];
        $out['pageSubtitle'] = self::plain($raw['pageSubtitle'] ?? $defaults['pageSubtitle'], 160)
            ?: $defaults['pageSubtitle'];
        $out['logoUrl'] = self::logoUrl($raw['logoUrl'] ?? '');
        // 只保留一套明亮模式 LOGO，旧版深色 LOGO 不再使用。
        $out['logoDarkUrl'] = '';
        $out['accentColor'] = self::paletteColor($raw['accentColor'] ?? '', '#5b5bd6');
        $out['accentColor2'] = self::paletteColor($raw['accentColor2'] ?? '', '#7c6cff');
        $out['uploadChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['uploadChannelId'] ?? ''))
            ? (string) ($raw['uploadChannelId'] ?? '')
            : '';
        $out['githubSecret'] = mb_substr(trim((string) ($raw['githubSecret'] ?? '')), 0, 200, 'UTF-8');
        $out['githubApiToken'] = mb_substr(trim((string) ($raw['githubApiToken'] ?? '')), 0, 300, 'UTF-8');
        $out['githubChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['githubChannelId'] ?? ''))
            ? (string) ($raw['githubChannelId'] ?? '')
            : '';
        $out['githubCardNote'] = mb_substr(trim((string) ($raw['githubCardNote'] ?? '')), 0, 1000, 'UTF-8');
        $out['welcomeChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['welcomeChannelId'] ?? ''))
            ? (string) ($raw['welcomeChannelId'] ?? '')
            : '';
        $greeting = trim((string) ($raw['welcomeGreeting'] ?? ''));
        $out['welcomeGreeting'] = mb_substr(
            '' !== $greeting ? $greeting : (string) $defaults['welcomeGreeting'],
            0,
            300,
            'UTF-8'
        );
        $out['claimChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['claimChannelId'] ?? ''))
            ? (string) ($raw['claimChannelId'] ?? '')
            : '';
        $out['claimRoles'] = mb_substr(trim((string) ($raw['claimRoles'] ?? '')), 0, 4000, 'UTF-8');
        $out['postChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['postChannelId'] ?? ''))
            ? (string) ($raw['postChannelId'] ?? '')
            : '6161751426312745';
        $out['postCardTitle'] = mb_substr(trim((string) ($raw['postCardTitle'] ?? '')), 0, 120, 'UTF-8');
        $out['postCardBody'] = mb_substr(trim((string) ($raw['postCardBody'] ?? '')), 0, 3000, 'UTF-8');
        $out['aiEnabled'] = '1' === (string) ($raw['aiEnabled'] ?? '0') ? '1' : '0';
        $out['aiApiKey'] = trim((string) ($raw['aiApiKey'] ?? ''));
        $out['aiBaseUrl'] = '' !== trim((string) ($raw['aiBaseUrl'] ?? ''))
            ? mb_substr(trim((string) $raw['aiBaseUrl']), 0, 200, 'UTF-8')
            : 'https://api.deepseek.com';
        $out['aiModel'] = '' !== trim((string) ($raw['aiModel'] ?? ''))
            ? mb_substr(trim((string) $raw['aiModel']), 0, 80, 'UTF-8')
            : 'deepseek-chat';
        $out['aiSystemPrompt'] = mb_substr(trim((string) ($raw['aiSystemPrompt'] ?? '')), 0, 8000, 'UTF-8');
        $out['aiTemperature'] = (string) max(0.0, min(2.0, (float) ($raw['aiTemperature'] ?? 0.8)));
        $out['aiMaxTokens'] = (string) max(80, min(800, (int) ($raw['aiMaxTokens'] ?? 180)));
        $out['aiHistoryLimit'] = (string) max(2, min(12, (int) ($raw['aiHistoryLimit'] ?? 6)));
        $out['aiChannelId'] = preg_match('/^\d{0,32}$/', (string) ($raw['aiChannelId'] ?? ''))
            ? (string) ($raw['aiChannelId'] ?? '')
            : '';
        $out['aiReplyAll'] = '1' === (string) ($raw['aiReplyAll'] ?? '0') ? '1' : '0';

        if ($strict) {
            if ('' !== (string) $out['guildId'] && !preg_match('/^\d{3,32}$/', (string) $out['guildId'])) {
                $errors['guildId'] = 'KOOK 服务器 ID 必须是数字。';
            }
            if ('' !== (string) $out['ownerKookId'] && !preg_match('/^\d{3,32}$/', (string) $out['ownerKookId'])) {
                $errors['ownerKookId'] = '所有者 KOOK ID 必须是数字。';
            }
        }

        return $out;
    }

    /**
     * 保存设置到持久化表，并同步 `plugin:MoePanel` 选项。
     *
     * @throws PluginException
     */
    public static function save(array $settings): array
    {
        $normalized = self::normalize($settings);

        try {
            self::writeStore($normalized);
            self::writeOption($normalized);
        } catch (\Throwable $e) {
            throw new PluginException('KOOK 设置保存失败：' . $e->getMessage());
        }

        self::$cache = $normalized;
        return $normalized;
    }

    /**
     * 激活插件时：选项不存在则从持久化表恢复；选项存在则以选项为准。
     */
    public static function bootstrapActivation(): void
    {
        $option = self::readOption();
        $stored = self::readStoreSafe();

        if (null !== $option) {
            self::save($option);
            return;
        }

        self::save($stored ?? self::defaults());
    }

    /**
     * 禁用插件前：把可能刚通过标准表单保存的选项合并进持久化表。
     */
    public static function importOptionToStore(): void
    {
        $option = self::readOption();
        if (null === $option) {
            return;
        }

        try {
            self::writeStore($option);
            self::$cache = $option;
        } catch (\Throwable $e) {
            // 禁用流程不应因为存储异常而中断。
        }
    }

    /**
     * 后台请求中的双向镜像：
     * - 选项缺失 -> 用持久化表恢复（防止 Typecho 删除后配置丢失）
     * - 选项存在但和持久化表不同 -> 选项胜出（标准表单保存仍然持久化）
     */
    public static function syncFromOption(): void
    {
        try {
            $option = self::readOption();

            if (null === $option) {
                $stored = self::readStoreSafe() ?? self::defaults();
                self::writeOption($stored);
                self::$cache = $stored;
                return;
            }

            $stored = self::readStoreSafe();

            if (null === $stored || $stored !== $option) {
                self::writeStore($option);
                self::$cache = $option;
            }
        } catch (\Throwable $e) {
            // 同步是尽力而为，绝不能让后台 500。
        }
    }

    public static function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }

        self::createFlowTableIfNeeded();
        self::createProfileTableIfNeeded();

        $db = Db::get();
        $prefix = $db->getPrefix();

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}" . self::STORE_TABLE . "` ("
            . "`name` VARCHAR(64) NOT NULL PRIMARY KEY,"
            . "`value` TEXT NOT NULL,"
            . "`updated` INTEGER NOT NULL DEFAULT 0"
            . ")";

        $db->query($sql, Db::WRITE);
        self::$tableReady = true;
    }

    /**
     * 验证码流程表（激活时创建，禁用时保留）。
     */
    public static function createFlowTableIfNeeded(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}" . self::FLOW_TABLE . "` ("
            . "`flow` VARCHAR(64) NOT NULL PRIMARY KEY,"
            . "`kook_id` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`nickname` VARCHAR(128) NOT NULL DEFAULT '',"
            . "`code_hash` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`expires` INTEGER NOT NULL DEFAULT 0,"
            . "`attempts` INTEGER NOT NULL DEFAULT 0,"
            . "`created` INTEGER NOT NULL DEFAULT 0,"
            . "`ip` VARCHAR(64) NOT NULL DEFAULT ''"
            . ")";

        $db->query($sql, Db::WRITE);
    }

    /**
     * KOOK 头像/昵称缓存（用于后台侧边栏和概要页展示）。
     */
    public static function createProfileTableIfNeeded(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}" . self::PROFILE_TABLE . "` ("
            . "`kook_id` VARCHAR(64) NOT NULL PRIMARY KEY,"
            . "`uid` INTEGER NOT NULL DEFAULT 0,"
            . "`username` VARCHAR(128) NOT NULL DEFAULT '',"
            . "`identify_num` VARCHAR(32) NOT NULL DEFAULT '',"
            . "`nickname` VARCHAR(128) NOT NULL DEFAULT '',"
            . "`avatar` VARCHAR(500) NOT NULL DEFAULT '',"
            . "`updated` INTEGER NOT NULL DEFAULT 0"
            . ")";

        $db->query($sql, Db::WRITE);
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    private static function readStoreSafe(): ?array
    {
        try {
            return self::readStore();
        } catch (\Throwable $e) {
            try {
                self::ensureTable();
                return self::readStore();
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    private static function readStore(): ?array
    {
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select('value')
                ->from('table.' . self::STORE_TABLE)
                ->where('name = ?', self::STORE_KEY)
                ->limit(1)
        );

        if (empty($row['value'])) {
            return null;
        }

        $decoded = json_decode((string) $row['value'], true);
        return is_array($decoded) ? self::normalize($decoded) : null;
    }

    private static function writeStore(array $settings): void
    {
        self::ensureTable();

        $db = Db::get();
        $value = json_encode(self::normalize($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $affected = $db->query(
            $db->update('table.' . self::STORE_TABLE)
                ->rows(['value' => $value, 'updated' => time()])
                ->where('name = ?', self::STORE_KEY)
        );

        if (!$affected) {
            try {
                $db->query(
                    $db->insert('table.' . self::STORE_TABLE)
                        ->rows(['name' => self::STORE_KEY, 'value' => $value, 'updated' => time()])
                );
            } catch (\Throwable $e) {
                // 并发插入：改为更新。
                $db->query(
                    $db->update('table.' . self::STORE_TABLE)
                        ->rows(['value' => $value, 'updated' => time()])
                        ->where('name = ?', self::STORE_KEY)
                );
            }
        }
    }

    private static function readOption(): ?array
    {
        foreach ([self::OPTION_NAME, self::LEGACY_OPTION_NAME] as $name) {
            $option = self::readOptionByName($name);

            if (null !== $option) {
                return $option;
            }
        }

        return null;
    }

    private static function readOptionByName(string $name): ?array
    {
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select('value')
                ->from('table.options')
                ->where('name = ?', $name)
                ->where('user = ?', 0)
                ->limit(1)
        );

        if (empty($row['value'])) {
            return null;
        }

        $value = (string) $row['value'];
        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            $unserialized = @unserialize($value);
            $decoded = is_array($unserialized) ? $unserialized : null;
        }

        return is_array($decoded) ? self::normalize($decoded) : null;
    }

    private static function writeOption(array $settings): void
    {
        $db = Db::get();
        $value = json_encode(self::normalize($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $affected = $db->query(
            $db->update('table.options')
                ->rows(['value' => $value])
                ->where('name = ?', self::OPTION_NAME)
                ->where('user = ?', 0)
        );

        if (!$affected) {
            $exists = $db->fetchRow(
                $db->select('name')
                    ->from('table.options')
                    ->where('name = ?', self::OPTION_NAME)
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (empty($exists)) {
                $db->query(
                    $db->insert('table.options')
                        ->rows(['name' => self::OPTION_NAME, 'value' => $value, 'user' => 0])
                );
            }
        }
    }

    private static function flag($value): string
    {
        if (true === $value || 1 === $value) {
            return '1';
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'on', 'true', 'yes', 'enable', 'enabled'], true) ? '1' : '0';
    }

    private static function plain($value, int $max): string
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return self::limit($value, $max);
    }

    private static function secret($value): string
    {
        if (is_array($value)) {
            $value = implode('', array_map('strval', $value));
        }

        $value = trim((string) $value);
        return self::limit($value, 512);
    }

    private static function intRange($value, int $min, int $max, int $default): string
    {
        if (!is_numeric($value)) {
            return (string) $default;
        }

        $number = (int) round((float) $value);

        if ($number < $min) {
            $number = $min;
        } elseif ($number > $max) {
            $number = $max;
        }

        return (string) $number;
    }

    private static function paletteColor($value, string $default): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^#[0-9A-F]{6}$/', $value) ? strtolower($value) : $default;
    }

    private static function color($value): string
    {
        $value = strtoupper(trim((string) $value));

        if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
            return '#FFCDCC';
        }

        return $value;
    }

    private static function multiline($value, int $max, string $default): string
    {
        if (is_array($value)) {
            $value = implode("\n", array_map('strval', $value));
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = self::limit(trim($value), $max);

        return '' === $value ? $default : $value;
    }

    private static function logoUrl($value): string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return '';
        }

        if (strlen($value) > 500 || preg_match('/[\x00-\x20]/', $value)) {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $value) || 0 === strpos($value, '/')) {
            return $value;
        }

        return '';
    }

    private static function limit(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }
}
