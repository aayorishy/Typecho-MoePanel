<?php

namespace TypechoPlugin\MoePanel\Kook;

/**
 * KOOK 开放平台 / 机器人 API 客户端。
 *
 * - Bot API 默认 https://www.kookapp.cn/api/v3
 * - OAuth 默认 https://www.kookapp.cn
 * - 测试时可通过环境变量 KOOK_API_BASE / KOOK_OAUTH_BASE 覆盖。
 */
class KookClient
{
    public const DEFAULT_API_BASE = 'https://www.kookapp.cn/api/v3';
    public const DEFAULT_OAUTH_BASE = 'https://www.kookapp.cn';
    public const DEFAULT_CARD_COLOR = '#FFCDCC';

    private string $apiBase;
    private string $oauthBase;
    private string $botToken;
    private int $timeout = 8;

    public function __construct(string $botToken = '', ?string $apiBase = null, ?string $oauthBase = null)
    {
        $this->apiBase = rtrim($apiBase ?: self::env('KOOK_API_BASE', self::DEFAULT_API_BASE), '/');
        $this->oauthBase = rtrim($oauthBase ?: self::env('KOOK_OAUTH_BASE', self::DEFAULT_OAUTH_BASE), '/');
        $this->botToken = trim($botToken);
    }

    /**
     * 获取 WebSocket 网关地址（compress=0，避免二进制压缩帧）。
     *
     * @throws KookException
     */
    public function gatewayUrl(): string
    {
        $result = $this->request('GET', $this->apiBase . '/gateway/index?compress=0');
        $url = (string) ($result['data']['url'] ?? '');

        if ('' === $url) {
            throw new KookException('KOOK 未返回 WebSocket 网关地址');
        }

        return $url;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = max(2, min(20, $timeout));
    }

    public function apiBase(): string
    {
        return $this->apiBase;
    }

    public function oauthBase(): string
    {
        return $this->oauthBase;
    }

    public function setBotToken(string $token): void
    {
        $this->botToken = trim($token);
    }

    /**
     * 生成 OAuth 授权地址。
     */
    public function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        return $this->oauthBase . '/oauth2/authorize?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'get_user_info',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 授权码换 access_token。
     *
     * @throws KookException
     */
    public function oauthToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        $result = $this->request(
            'POST',
            $this->oauthBase . '/api/oauth2/token',
            null,
            [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]
        );

        if (empty($result['access_token'])) {
            throw new KookException('KOOK 未返回 access_token');
        }

        return $result;
    }

    /**
     * OAuth 用户信息。
     *
     * @throws KookException
     */
    public function oauthUser(string $accessToken): array
    {
        $result = $this->request('GET', $this->apiBase . '/user/me', null, null, $accessToken);

        $data = $result['data'] ?? $result;
        if (!is_array($data) || empty($data['id'])) {
            throw new KookException('无法读取 KOOK 用户信息');
        }

        return self::userSummary($data);
    }

    /**
     * @throws KookException
     */
    public function userView(string $userId, string $guildId = ''): array
    {
        $query = ['user_id' => $userId];
        if ('' !== $guildId) {
            $query['guild_id'] = $guildId;
        }

        try {
            $result = $this->request('GET', $this->apiBase . '/user/view?' . http_build_query($query));
        } catch (KookException $e) {
            if ('' === $guildId) {
                throw $e;
            }
            $query = ['user_id' => $userId];
            $result = $this->request('GET', $this->apiBase . '/user/view?' . http_build_query($query));
        }
        $data = $result['data'] ?? null;

        if (!is_array($data) || empty($data['id'])) {
            throw new KookException('未找到该 KOOK 用户');
        }

        return self::userSummary($data);
    }

    /**
     * 服务器成员搜索。
     *
     * @throws KookException
     */
    public function guildUserSearch(string $guildId, string $search, int $page = 1, int $pageSize = 50): array
    {
        $query = [
            'guild_id' => $guildId,
            'search' => $search,
            'page' => max(1, $page),
            'page_size' => max(1, min(100, $pageSize)),
        ];

        $result = $this->request(
            'GET',
            $this->apiBase . '/guild/user-list?' . http_build_query($query)
        );

        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            return ['items' => [], 'page_total' => 0];
        }

        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'page_total' => (int) ($data['meta']['page_total'] ?? 1),
        ];
    }

    /**
     * 解析用户输入：数字 ID、nickname#1234、username#1234。
     *
     * @throws KookException
     */
    public function resolveIdentity(string $identity, string $guildId): array
    {
        $identity = trim($identity);

        if ('' === $identity) {
            throw new KookException('请输入 KOOK 昵称#编号 或 用户 ID');
        }

        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_\-\.#\s]{1,128}$/u', $identity)) {
            throw new KookException('账号格式不正确，请使用 昵称#编号 或 数字用户 ID');
        }

        if (preg_match('/^\d{3,32}$/', $identity)) {
            return $this->userView($identity, $guildId);
        }

        if ('' === $guildId) {
            throw new KookException('未配置 KOOK 服务器 ID，无法解析昵称，请直接输入数字用户 ID');
        }

        $hash = strrpos($identity, '#');

        if (false === $hash) {
            return $this->searchUniqueMember($guildId, $identity, null);
        }

        $name = trim(substr($identity, 0, $hash));
        $number = trim(substr($identity, $hash + 1));

        if ('' === $name || '' === $number) {
            throw new KookException('账号格式不正确，请使用 昵称#编号');
        }

        return $this->searchUniqueMember($guildId, $name, $number);
    }

    /**
     * @throws KookException
     */
    private function searchUniqueMember(string $guildId, string $name, ?string $number): array
    {
        $needle = self::lower($name);
        $matches = [];

        for ($page = 1; $page <= 5; $page++) {
            $result = $this->guildUserSearch($guildId, $name, $page, 50);

            foreach ($result['items'] as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }

                $username = self::lower((string) ($item['username'] ?? ''));
                $nickname = self::lower((string) ($item['nickname'] ?? ''));
                $identify = (string) ($item['identify_num'] ?? '');

                if (null !== $number) {
                    if ($identify === $number && ($username === $needle || $nickname === $needle)) {
                        return self::userSummary($item);
                    }
                } elseif ($username === $needle || $nickname === $needle) {
                    $matches[(string) $item['id']] = $item;
                }
            }

            if ($page >= $result['page_total'] || count($result['items']) === 0) {
                break;
            }
        }

        if (null === $number) {
            if (count($matches) === 1) {
                return self::userSummary((array) reset($matches));
            }

            if (count($matches) > 1) {
                throw new KookException('找到多个匹配用户，请使用 昵称#编号 重试');
            }
        }

        throw new KookException('未找到匹配的 KOOK 用户，请确认该账号已加入配置的服务器');
    }

    /**
     * 发送卡片私信（type=10）。
     *
     * @throws KookException
     */
    public function guildList(): array
    {
        $result = $this->request('GET', $this->apiBase . '/guild/list?' . http_build_query([
            'page' => 1,
            'page_size' => 50,
        ]));

        return is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
    }

    public function channelList(string $guildId): array
    {
        $result = $this->request('GET', $this->apiBase . '/channel/list?' . http_build_query([
            'guild_id' => $guildId,
            'page' => 1,
            'page_size' => 100,
        ]));

        return is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
    }

    public function guildRoleList(string $guildId): array
    {
        $result = $this->request('GET', $this->apiBase . '/guild-role/list?' . http_build_query([
            'guild_id' => $guildId,
        ]));

        return is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
    }

    public function guildUsers(string $guildId, int $pageSize = 100): array
    {
        $result = $this->request('GET', $this->apiBase . '/guild/user-list?' . http_build_query([
            'guild_id' => $guildId,
            'page' => 1,
            'page_size' => $pageSize,
        ]));

        return is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
    }

    public function guildMemberCount(string $guildId): int
    {
        try {
            $result = $this->request('GET', $this->apiBase . '/guild/user-list?' . http_build_query([
                'guild_id' => $guildId,
                'page' => 1,
                'page_size' => 1,
            ]));
            $count = (int) ($result['data']['user_count'] ?? $result['data']['meta']['total'] ?? 0);

            if ($count > 0) {
                return $count;
            }
        } catch (\Throwable $ignored) {
        }

        try {
            $guild = $this->guildView($guildId);
            return (int) ($guild['user_count'] ?? $guild['member_count'] ?? 0);
        } catch (\Throwable $ignored) {
        }

        return 0;
    }

    public function guildView(string $guildId): array
    {
        $result = $this->request('GET', $this->apiBase . '/guild/view?' . http_build_query([
            'guild_id' => $guildId,
        ]));

        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * 汇总设置页需要的服务器目录：频道、身份组、成员、频道权限概览。
     */
    public function directory(string $guildId): array
    {
        $guild = [];
        $channels = [];
        $roles = [];
        $users = [];

        try { $guild = $this->guildView($guildId); } catch (\Throwable $e) {}
        try { $channels = $this->channelList($guildId); } catch (\Throwable $e) {}
        try { $roles = $this->guildRoleList($guildId); } catch (\Throwable $e) {}
        try { $users = $this->guildUsers($guildId); } catch (\Throwable $e) {}

        if ([] === $channels && is_array($guild['channels'] ?? null)) {
            $channels = $guild['channels'];
        }

        if ([] === $roles && is_array($guild['roles'] ?? null)) {
            $roles = $guild['roles'];
        }

        $channelItems = [];

        foreach ($channels as $channel) {
            if (!is_array($channel) || empty($channel['id'])) {
                continue;
            }

            $channelItems[] = [
                'id' => (string) $channel['id'],
                'name' => (string) ($channel['name'] ?? ('频道 ' . $channel['id'])),
                'type' => (int) ($channel['type'] ?? 1),
                'isCategory' => !empty($channel['is_category']),
                'permissionSync' => (int) ($channel['permission_sync'] ?? 0),
                'permissionOverwrites' => is_array($channel['permission_overwrites'] ?? null)
                    ? count($channel['permission_overwrites']) : 0,
            ];
        }

        $roleItems = [];

        foreach ($roles as $role) {
            if (!is_array($role) || empty($role['role_id'])) {
                continue;
            }

            $roleName = trim((string) ($role['name'] ?? ''));

            $roleItems[] = [
                'id' => (string) $role['role_id'],
                'name' => '' !== $roleName ? $roleName : ('角色 ' . $role['role_id']),
                'color' => (int) ($role['color'] ?? 0),
                'position' => (int) ($role['position'] ?? 0),
            ];
        }

        $userItems = [];

        foreach ($users as $user) {
            if (!is_array($user) || empty($user['id'])) {
                continue;
            }

            $userItems[] = [
                'id' => (string) $user['id'],
                'name' => (string) ($user['nickname'] ?? $user['username'] ?? $user['id']),
                'avatar' => (string) ($user['avatar'] ?? ''),
                'online' => !empty($user['online']),
                'bot' => !empty($user['bot']),
            ];
        }

        return [
            'guild' => [
                'id' => $guildId,
                'name' => (string) ($guild['name'] ?? ''),
            ],
            'channels' => $channelItems,
            'roles' => $roleItems,
            'users' => $userItems,
        ];
    }

    public function startGameActivity(string $name): array
    {
        $gameId = $this->findOrCreateGame($name);

        return $this->request('POST', $this->apiBase . '/game/activity', null, [
            'id' => $gameId,
            'data_type' => 1,
        ]);
    }

    public function startMusicActivity(string $song, string $singer, string $software = 'cloudmusic'): array
    {
        $payload = [
            'data_type' => 2,
            'software' => in_array($software, ['cloudmusic', 'qqmusic', 'kugou'], true) ? $software : 'cloudmusic',
            'singer' => $singer,
            'music_name' => $song,
        ];

        try {
            return $this->request('POST', $this->apiBase . '/game/activity', null, $payload);
        } catch (KookException $firstError) {
            // Some KOOK tenants require a game id even for music activities.
            try {
                $payload['id'] = $this->findOrCreateGame('音乐');
                return $this->request('POST', $this->apiBase . '/game/activity', null, $payload);
            } catch (\Throwable $retryError) {
                throw new KookException($firstError->getMessage() . ' / 带游戏 ID 重试：' . $retryError->getMessage());
            }
        }
    }

    public function stopActivity(int $dataType = 1): array
    {
        return $this->request('POST', $this->apiBase . '/game/delete-activity', null, [
            'data_type' => 1 === $dataType ? 1 : 2,
        ]);
    }

    public function inviteList(string $guildId = '', string $channelId = ''): array
    {
        $query = ['page' => 1, 'page_size' => 50];

        if ('' !== $guildId) {
            $query['guild_id'] = $guildId;
        }

        if ('' !== $channelId) {
            $query['channel_id'] = $channelId;
        }

        $result = $this->request('GET', $this->apiBase . '/invite/list?' . http_build_query($query));

        return is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
    }

    public function inviteCreate(string $guildId = '', string $channelId = '', int $duration = 604800, int $times = -1): array
    {
        $payload = ['duration' => $duration, 'setting_times' => $times];

        if ('' !== $guildId) {
            $payload['guild_id'] = $guildId;
        }

        if ('' !== $channelId) {
            $payload['channel_id'] = $channelId;
        }

        return $this->request('POST', $this->apiBase . '/invite/create', null, $payload);
    }

    public function inviteDelete(string $urlCode, string $guildId = '', string $channelId = ''): array
    {
        $payload = ['url_code' => $urlCode];

        if ('' !== $guildId) {
            $payload['guild_id'] = $guildId;
        }

        if ('' !== $channelId) {
            $payload['channel_id'] = $channelId;
        }

        return $this->request('POST', $this->apiBase . '/invite/delete', null, $payload);
    }

    private function findOrCreateGame(string $name): int
    {
        $name = trim($name);

        if ('' === $name) {
            throw new KookException('请填写游戏名称');
        }

        $list = $this->request('GET', $this->apiBase . '/game?type=1');

        foreach ((array) ($list['data']['items'] ?? []) as $game) {
            if (is_array($game) && 0 === strcasecmp((string) ($game['name'] ?? ''), $name)) {
                return (int) $game['id'];
            }
        }

        $created = $this->request('POST', $this->apiBase . '/game/create', null, ['name' => $name]);
        $id = (int) ($created['data']['id'] ?? 0);

        if ($id <= 0) {
            throw new KookException('创建游戏记录失败');
        }

        return $id;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function guildMemberRoles(string $guildId, string $userId): array
    {
        $result = $this->request('GET', $this->apiBase . '/guild/user-list?' . http_build_query([
            'guild_id' => $guildId,
            'search' => $userId,
            'page' => 1,
            'page_size' => 50,
        ]));

        foreach ((array) ($result['data']['items'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $userId) {
                return array_map('strval', is_array($item['roles'] ?? null) ? $item['roles'] : []);
            }
        }

        return [];
    }

    public function grantRole(string $guildId, string $userId, string $roleId): array
    {
        return $this->request('POST', $this->apiBase . '/guild-role/grant', [
            'guild_id' => $guildId,
            'user_id' => $userId,
            'role_id' => (int) $roleId,
        ]);
    }

    public function revokeRole(string $guildId, string $userId, string $roleId): array
    {
        return $this->request('POST', $this->apiBase . '/guild-role/revoke', [
            'guild_id' => $guildId,
            'user_id' => $userId,
            'role_id' => (int) $roleId,
        ]);
    }

    public function sendChannelCardPayload(string $channelId, array $card): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送频道消息');
        }

        return $this->postMessage('/message/create', [
            'type' => 10,
            'target_id' => $channelId,
            'content' => json_encode([$card], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function sendChannelCard(
        string $channelId,
        string $color,
        string $title,
        string $kmarkdown,
        array $context = []
    ): array {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送频道消息');
        }

        $card = $this->buildCard($color, $title, $kmarkdown, $context);

        return $this->request(
            'POST',
            $this->apiBase . '/message/create',
            [
                'type' => 10,
                'target_id' => $channelId,
                'content' => json_encode([$card], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function sendChannelImage(string $channelId, string $imageUrl): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送频道消息');
        }

        return $this->postMessage('/message/create', [
            'type' => 2,
            'target_id' => $channelId,
            'content' => $imageUrl,
        ]);
    }

    public function sendChannelText(string $channelId, string $content): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送频道消息');
        }

        return $this->postMessage('/message/create', [
            'type' => 9,
            'target_id' => $channelId,
            'content' => $content,
        ]);
    }

    public function sendDirectText(string $userId, string $content): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送私信');
        }

        return $this->postMessage('/direct-message/create', [
            'type' => 9,
            'target_id' => $userId,
            'content' => $content,
        ]);
    }

    public function sendDirectCardPayload(string $userId, array $card): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送私信');
        }

        return $this->postMessage('/direct-message/create', [
            'target_id' => $userId,
            'type' => 10,
            'content' => json_encode([$card], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function sendCard(string $userId, string $color, string $title, string $kmarkdown, array $context = []): array
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法发送私信');
        }

        $card = $this->buildCard($color, $title, $kmarkdown, $context);

        return $this->request(
            'POST',
            $this->apiBase . '/direct-message/create',
            [
                'target_id' => $userId,
                'type' => 10,
                // KOOK 的 content 必须是“卡片数组”的 JSON 字符串。
                'content' => json_encode([$card], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /**
     * 通过机器人把本地图片上传到 KOOK 资产库，返回 KOOK CDN URL。
     *
     * @throws KookException
     */
    public function uploadAsset(string $filePath, string $fileName = '', string $mime = ''): string
    {
        if ('' === $this->botToken) {
            throw new KookException('机器人 Token 未配置，无法上传图片');
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new KookException('图片文件不可读');
        }

        if (!function_exists('curl_init')) {
            throw new KookException('服务器需要启用 cURL 才能上传图片');
        }

        $fileName = '' !== $fileName ? $fileName : basename($filePath);
        $mime = '' !== $mime ? $mime : (function_exists('mime_content_type') ? (string) mime_content_type($filePath) : '');
        $ch = curl_init($this->apiBase . '/asset/create');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($filePath, $mime, $fileName)],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bot ' . $this->botToken,
                'User-Agent: MoePanel-Kook/' . Settings::VERSION,
            ],
        ];

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (false === $result) {
            throw new KookException('图片上传失败：' . $this->sanitize($error ?: '网络错误'));
        }

        $decoded = json_decode((string) $result, true);

        if (!is_array($decoded)) {
            throw new KookException('KOOK 返回了无法解析的上传结果（HTTP ' . $status . '）');
        }

        $apiCode = isset($decoded['code']) ? (int) $decoded['code'] : 0;

        if ($status >= 400 || 0 !== $apiCode) {
            $message = (string) ($decoded['message'] ?? '上传失败');
            throw new KookException($this->sanitize($message));
        }

        $url = (string) ($decoded['data']['url'] ?? '');

        if ('' === $url) {
            throw new KookException('KOOK 未返回图片地址');
        }

        return $url;
    }

    /**
     * 把已上传到 KOOK 资产库的图片发送到频道或所有者私信，再取回消息中的 KOOK 图片地址。
     * 这样得到的是 assets/... 形式的消息图片，而不是不可搜索的 attachments/... 临时资产。
     *
     * @throws KookException
     */
    public function publishImage(string $assetUrl, string $channelId = '', string $ownerId = ''): string
    {
        if ('' !== $channelId) {
            $result = $this->request('POST', $this->apiBase . '/message/create', [
                'type' => 2,
                'target_id' => $channelId,
                'content' => $assetUrl,
            ]);
            $msgId = (string) ($result['data']['msg_id'] ?? '');

            if ('' === $msgId) {
                throw new KookException('KOOK 未返回图片消息 ID');
            }

            $url = $this->waitForImageUrl(function () use ($msgId, $channelId): string {
                $view = $this->request('GET', $this->apiBase . '/message/view?' . http_build_query(['msg_id' => $msgId]));
                $url = self::messageImageUrl($view['data'] ?? []);

                if ('' !== $url) {
                    return $url;
                }

                $list = $this->request('GET', $this->apiBase . '/message/list?' . http_build_query([
                    'target_id' => $channelId,
                    'msg_id' => $msgId,
                    'flag' => 'around',
                    'page_size' => 20,
                ]));

                foreach ((array) ($list['data']['items'] ?? []) as $item) {
                    if (is_array($item) && (string) ($item['id'] ?? '') === $msgId) {
                        return self::messageImageUrl($item);
                    }
                }

                return '';
            });

            if ('' === $url) {
                throw new KookException('KOOK 消息已发送，但未读取到图片地址，请稍后重试');
            }

            return $url;
        }

        if ('' === $ownerId) {
            throw new KookException('请先配置图片上传频道，或填写所有者 KOOK ID 以使用私信');
        }

        $result = $this->request('POST', $this->apiBase . '/direct-message/create', [
            'type' => 2,
            'target_id' => $ownerId,
            'content' => $assetUrl,
        ]);
        $msgId = (string) ($result['data']['msg_id'] ?? '');

        if ('' === $msgId) {
            throw new KookException('KOOK 未返回私信消息 ID');
        }

        $chatCode = (string) ($result['data']['chat_code'] ?? '');
        $url = $this->waitForImageUrl(function () use ($msgId, $ownerId, $chatCode): string {
            if ('' !== $chatCode) {
                $view = $this->request('GET', $this->apiBase . '/direct-message/view?' . http_build_query([
                    'msg_id' => $msgId,
                    'chat_code' => $chatCode,
                ]));
                $url = self::messageImageUrl($view['data'] ?? []);

                if ('' !== $url) {
                    return $url;
                }
            }

            $list = $this->request('GET', $this->apiBase . '/direct-message/list?' . http_build_query([
                'target_id' => $ownerId,
                'page_size' => 50,
            ]));

            foreach ((array) ($list['data']['items'] ?? []) as $item) {
                if (is_array($item) && (string) ($item['id'] ?? '') === $msgId) {
                    return self::messageImageUrl($item);
                }
            }

            return '';
        });

        if ('' === $url) {
            throw new KookException('KOOK 私信已发送，但未读取到图片地址，请稍后重试');
        }

        return $url;
    }

    private function waitForImageUrl(callable $probe): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $url = $probe();

                if ('' !== $url) {
                    return $url;
                }
            } catch (KookException $e) {
                // 消息可能还在 KOOK 侧处理中，继续短轮询。
            }

            usleep(400000);
        }

        return '';
    }

    private static function messageImageUrl(array $message): string
    {
        $url = self::attachmentUrl($message['attachments'] ?? null);

        if ('' !== $url) {
            return $url;
        }

        $content = trim((string) ($message['content'] ?? ''));

        if (preg_match('~^https?://\S+$~i', $content)) {
            return $content;
        }

        foreach ((array) ($message['embeds'] ?? []) as $embed) {
            if (is_array($embed)) {
                foreach (['thumbnail', 'pic', 'url'] as $key) {
                    if (!empty($embed[$key]) && is_string($embed[$key])) {
                        return $embed[$key];
                    }
                }
            }
        }

        return '';
    }

    private static function attachmentUrl($attachments): string
    {
        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);

            if (is_array($decoded)) {
                return self::attachmentUrl($decoded);
            }

            return preg_match('~^https?://~i', $attachments) ? $attachments : '';
        }

        if (!is_array($attachments)) {
            return '';
        }

        if (isset($attachments['url']) && is_string($attachments['url'])) {
            return $attachments['url'];
        }

        foreach ($attachments as $attachment) {
            if (is_string($attachment) && preg_match('~^https?://~i', $attachment)) {
                return $attachment;
            }

            if (is_array($attachment) && !empty($attachment['url'])) {
                return (string) $attachment['url'];
            }
        }

        return '';
    }

    /**
     * 卡片数组，永远不设置 theme，只使用 color。
     */
    public function buildCard(string $color, string $title, string $kmarkdown, array $context = []): array
    {
        $color = self::normalizeColor($color);
        $modules = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain-text',
                    'content' => self::limit($title, 100),
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'kmarkdown',
                    'content' => self::limit($kmarkdown, 5000),
                ],
            ],
        ];

        if (!empty($context)) {
            $elements = [];
            foreach (array_slice($context, 0, 8) as $line) {
                $elements[] = [
                    'type' => 'plain-text',
                    'content' => self::limit((string) $line, 200),
                ];
            }

            $modules[] = ['type' => 'divider'];
            $modules[] = ['type' => 'context', 'elements' => $elements];
        }

        return [
            'type' => 'card',
            'color' => $color,
            'size' => 'lg',
            'modules' => $modules,
        ];
    }

    public static function normalizeColor(string $color): string
    {
        $color = strtoupper(trim($color));
        return preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : self::DEFAULT_CARD_COLOR;
    }

    /**
     * 转义 KMarkdown 中的特殊字符。
     */
    public static function escapeKmarkdown(string $value): string
    {
        return preg_replace('/([\\\\`*_~\[\]()>])/u', '\\\\$1', $value) ?? $value;
    }

    public static function userSummary(array $data): array
    {
        return [
            'id' => (string) ($data['id'] ?? ''),
            'username' => (string) ($data['username'] ?? ''),
            'identify_num' => (string) ($data['identify_num'] ?? ''),
            'nickname' => (string) ($data['nickname'] ?? $data['username'] ?? ''),
            'avatar' => (string) ($data['avatar'] ?? ''),
            'online' => (bool) ($data['online'] ?? false),
        ];
    }

    /**
     * 统一请求入口。
     *
     * @throws KookException
     */
    public function me(): array
    {
        $decoded = $this->request('GET', $this->apiBase . '/user/me', null, null, null, true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * KOOK 不同网关对消息接口的 Content-Type 支持不一致：先 JSON，失败再表单。
     */
    private function postMessage(string $path, array $payload): array
    {
        try {
            return $this->request('POST', $this->apiBase . $path, $payload);
        } catch (KookException $jsonError) {
            try {
                return $this->request('POST', $this->apiBase . $path, null, $payload);
            } catch (\Throwable $formError) {
                throw new KookException(
                    $jsonError->getMessage() . ' / 表单重试：' . $formError->getMessage()
                );
            }
        }
    }

    private function request(
        string $method,
        string $url,
        ?array $json = null,
        ?array $form = null,
        ?string $bearer = null,
        bool $forceBot = false
    ): array {
        $headers = [
            'Accept: application/json',
            'User-Agent: MoePanel-Kook/' . Settings::VERSION . ' (Typecho)',
        ];

        $body = null;

        if (null !== $json) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (null !== $form) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }

        if (null !== $bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        } elseif ('' !== $this->botToken && ($forceBot || $this->isBotUrl($url))) {
            $headers[] = 'Authorization: Bot ' . $this->botToken;
        }

        [$status, $rawBody, $transportError] = $this->transport($method, $url, $headers, $body);

        if (null !== $transportError) {
            throw new KookException('无法连接 KOOK：' . $this->sanitize($transportError));
        }

        $decoded = json_decode((string) $rawBody, true);

        if (!is_array($decoded)) {
            $snippet = trim(strip_tags((string) $rawBody));
            throw new KookException('KOOK 返回了无法解析的数据' . ('' !== $snippet ? '（HTTP ' . $status . '）' : ''));
        }

        $apiCode = isset($decoded['code']) ? (int) $decoded['code'] : 0;

        if ($status >= 400 || 0 !== $apiCode) {
            $message = (string) ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? '');
            $message = '' !== $message ? $this->sanitize($message) : 'KOOK 请求失败';
            throw new KookException($message, $apiCode > 0 ? $apiCode : $status);
        }

        return $decoded;
    }

    /**
     * cURL 优先，失败时回退到 stream context。
     *
     * @return array{0:int,1:string,2:?string}
     */
    private function transport(string $method, string $url, array $headers, ?string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ];

            if (null !== $body) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (false === $result) {
                return [0, '', '' !== $error ? $error : '网络请求失败'];
            }

            return [$status, (string) $result, null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => null !== $body ? $body : '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $status = 0;

        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        if (false === $result) {
            $error = error_get_last();
            return [0, '', $error['message'] ?? '网络请求失败'];
        }

        return [$status, (string) $result, null];
    }

    private function isBotUrl(string $url): bool
    {
        return 0 === strpos($url, $this->apiBase . '/')
            && false === strpos($url, '/user/me');
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('#https?://\S+#i', '[url]', $message) ?? $message;

        if ('' !== $this->botToken) {
            $message = str_replace($this->botToken, '***', $message);
        }

        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? $message;

        return self::limit(trim($message), 200);
    }

    private static function limit(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return false !== $value && '' !== trim($value) ? trim($value) : $default;
    }
}

/**
 * 可安全展示给用户的 KOOK API 异常。
 */
class KookException extends \RuntimeException
{
}
