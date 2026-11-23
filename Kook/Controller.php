<?php

namespace TypechoPlugin\MoePanel\Kook;

use Typecho\Common;
use Typecho\Response;
use Widget\Options;
use Widget\Security;
use Widget\User;

/**
 * KOOK 登录控制器。
 *
 * 由 MoePanel 在 admin/common.php:begin_0 钩子中调用，早于后台外壳的
 * 输出缓冲。它只负责登录页路由、OAuth/验证码动作和设置自动保存端点；
 * HTML 由 Panel::renderAuth + templates/auth*.php 渲染。
 */
class Controller
{
    private static ?array $loginContext = null;

    /**
     * 由 Panel::renderAuth 读取。控制器只设置上下文，不再自己输出整页 HTML，
     * 这样 KOOK 登录可以复用 MoePanel 的左右分栏登录页。
     */
    public static function consumeContext(): ?array
    {
        $context = self::$loginContext;
        self::$loginContext = null;
        return $context;
    }

    public static function handleAdmin(): void
    {
        if ('cli' === PHP_SAPI) {
            return;
        }

        if (isset($_GET['kook_update'])) {
            self::handleUpdate();
            return;
        }

        if (isset($_GET['kook_ai'])) {
            self::handleAi();
            return;
        }

        if (isset($_GET['kook_welcome'])) {
            self::handleWelcomeTest();
            return;
        }

        if (isset($_GET['kook_presence'])) {
            self::handlePresence();
            return;
        }

        if (isset($_GET['kook_invite'])) {
            self::handleInvite();
            return;
        }

        if (isset($_GET['kook_directory'])) {
            self::handleDirectory();
            return;
        }

        if (isset($_GET['kook_claim'])) {
            self::handleClaimPublish();
            return;
        }

        if (isset($_GET['kook_bot'])) {
            self::handleBot();
            return;
        }

        if (isset($_GET['kook_upload'])) {
            self::handleAssetUpload();
            return;
        }

        if (isset($_GET['kook_save'])) {
            self::handleAjaxSave();
            return;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ('login.php' !== $script) {
            // 后台普通页面：在标准配置页渲染前完成选项 <-> 持久化表同步。
            try {
                $user = User::alloc();
                if ($user->hasLogin() && $user->pass('administrator', true)) {
                    Settings::syncFromOption();
                }
            } catch (\Throwable $e) {
                // 同步失败不能影响后台。
            }

            return;
        }

        self::handleLogin();
    }

    private static function handleLogin(): void
    {
        $passwordMode = isset($_GET['kook_password']) && '1' === (string) $_GET['kook_password'];

        if ($passwordMode) {
            // 核心 login.php 继续执行，保留密码登录兜底。
            return;
        }

        $options = Options::alloc();

        try {
            $user = User::alloc();
            if ($user->hasLogin()) {
                self::redirect((string) $options->adminUrl);
                return;
            }
        } catch (\Throwable $e) {
            // 未登录状态读取失败时继续渲染登录页。
        }

        try {
            $settings = Settings::load();
        } catch (\Throwable $e) {
            $settings = Settings::defaults();
        }

        $isCallback = isset($_GET['kook_oauth']) && 'callback' === (string) $_GET['kook_oauth'];

        if ('1' !== (string) ($settings['enable'] ?? '0')) {
            // 总开关关闭时完全不接管登录页，让核心 Typecho（或 MoePanel 等
            // 后台皮肤）照常渲染密码登录。这样“启用插件”本身不会造成锁死风险。
            return;
        }

        $availability = self::methodAvailability($settings);

        if (!$availability['any']) {
            self::renderFatal($settings, 'KOOK 登录未配置', self::missingConfigMessage($settings, $availability));
            return;
        }

        if ($isCallback) {
            self::handleOAuth($settings);
            return;
        }

        if ('POST' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) && isset($_POST['kook_action'])) {
            self::handlePost($settings);
            return;
        }

        self::renderLoginPage($settings, []);
    }

    private static function handlePost(array $settings): void
    {
        $cookie = isset($_COOKIE['kookauth_csrf']) ? (string) $_COOKIE['kookauth_csrf'] : '';
        $posted = isset($_POST['kook_csrf']) ? (string) $_POST['kook_csrf'] : '';

        if ('' === $cookie || '' === $posted || !hash_equals($cookie, $posted)) {
            self::renderLoginPage($settings, [
                'error' => '页面已过期，请重新提交。',
                'step' => 'identity',
            ]);
            return;
        }

        $action = isset($_POST['kook_action']) ? (string) $_POST['kook_action'] : '';

        if ('send' === $action) {
            $identity = isset($_POST['kook_identity']) ? trim((string) $_POST['kook_identity']) : '';

            if ('' === $identity) {
                self::renderLoginPage($settings, [
                    'error' => '请输入 KOOK 昵称#编号 或 用户 ID。',
                    'step' => 'identity',
                ]);
                return;
            }

            $result = Auth::startVerification($settings, $identity, self::clientIp());

            if (!empty($result['ok'])) {
                self::renderLoginPage($settings, [
                    'step' => 'code',
                    'flow' => (string) $result['flow'],
                    'masked' => (string) $result['masked'],
                ]);
                return;
            }

            self::renderLoginPage($settings, [
                'error' => (string) ($result['error'] ?? '验证码发送失败'),
                'step' => 'identity',
                'identity' => $identity,
            ]);
            return;
        }

        if ('verify' === $action) {
            $flow = isset($_POST['kook_flow']) ? (string) $_POST['kook_flow'] : '';
            $code = isset($_POST['kook_code']) ? preg_replace('/\D+/', '', (string) $_POST['kook_code']) : '';
            $result = Auth::verify($settings, $flow, (string) $code, self::clientIp());

            if (!empty($result['ok'])) {
                self::clearAuthCookies();
                $kookId = (string) $result['kook_id'];
                $uid = (int) ($result['uid'] ?? 0);

                if ($uid > 0) {
                    if (!Auth::completeLogin($uid, $kookId, $settings, self::clientIp())) {
                        self::renderLoginPage($settings, [
                            'error' => 'Typecho 用户登录失败，请稍后再试。',
                            'step' => 'identity',
                        ]);
                        return;
                    }
                    self::redirect((string) Options::alloc()->adminUrl);
                    return;
                }

                if ((int) $uid > 0) {
                    if (!Auth::completeLogin((int) $uid, $kookId, $settings, self::clientIp())) {
                        self::renderLoginPage($settings, [
                            'error' => '站点所有者登录失败，请稍后再试。',
                            'step' => 'identity',
                        ]);
                        return;
                    }
                    self::redirect((string) Options::alloc()->adminUrl);
                    return;
                }

                if (!Auth::completeGuestLogin($kookId, $settings, self::clientIp())) {
                    self::renderLoginPage($settings, [
                        'error' => 'KOOK 登录失败，请重新获取验证码。',
                        'step' => 'identity',
                    ]);
                    return;
                }
                self::redirect((string) Options::alloc()->siteUrl);
                return;
            }

            $step = 'code' === (string) ($result['step'] ?? 'identity') ? 'code' : 'identity';
            $context = [
                'error' => (string) ($result['error'] ?? '验证码校验失败'),
                'step' => $step,
            ];

            if ('code' === $step) {
                $context['flow'] = $flow;
                $context['masked'] = Auth::maskedForFlow($flow);
            }

            self::renderLoginPage($settings, $context);
            return;
        }

        self::renderLoginPage($settings, [
            'error' => '未知的登录操作。',
            'step' => 'identity',
        ]);
    }

    private static function handleOAuth(array $settings): void
    {
        $availability = self::methodAvailability($settings);

        if (!$availability['oauth']) {
            self::renderFatal(
                $settings,
                'OAuth 登录未配置',
                '请先在 MoePanel 设置中填写 KOOK Client ID 与 Client Secret。'
            );
            return;
        }

        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        $stateCookie = isset($_COOKIE['kookauth_state']) ? (string) $_COOKIE['kookauth_state'] : '';
        self::deleteCookie('kookauth_state');

        if ('' === $state || '' === $stateCookie || !hash_equals($stateCookie, $state)) {
            self::renderLoginPage($settings, [
                'error' => 'OAuth 状态校验失败，请重新发起登录。',
                'step' => 'identity',
            ]);
            return;
        }

        if (!empty($_GET['error'])) {
            self::renderLoginPage($settings, [
                'error' => 'KOOK 授权未完成或已被取消。',
                'step' => 'identity',
            ]);
            return;
        }

        $code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';

        if ('' === $code || strlen($code) > 512) {
            self::renderLoginPage($settings, [
                'error' => 'OAuth 回调缺少授权码。',
                'step' => 'identity',
            ]);
            return;
        }

        $client = new KookClient((string) ($settings['botToken'] ?? ''));

        try {
            $token = $client->oauthToken(
                $code,
                (string) $settings['clientId'],
                (string) $settings['clientSecret'],
                self::callbackUrl()
            );
            $user = $client->oauthUser((string) ($token['access_token'] ?? ''));
        } catch (KookException $e) {
            self::renderLoginPage($settings, [
                'error' => 'OAuth 登录失败：' . $e->getMessage(),
                'step' => 'identity',
            ]);
            return;
        } catch (\Throwable $e) {
            self::renderLoginPage($settings, [
                'error' => 'OAuth 登录失败，请稍后再试。',
                'step' => 'identity',
            ]);
            return;
        }

        $kookId = (string) ($user['id'] ?? '');
        $uid = Auth::uidFor($settings, $kookId);
        if (null !== $uid && !Auth::userExists($uid)) {
            $uid = null;
        }

        Auth::saveProfile($user, $uid ?? 0);
        self::clearAuthCookies();

        if ($uid > 0) {
            if (!Auth::completeLogin($uid, $kookId, $settings, self::clientIp())) {
                self::renderLoginPage($settings, [
                    'error' => 'Typecho 用户登录失败，请稍后再试。',
                    'step' => 'identity',
                ]);
                return;
            }
            self::redirect((string) Options::alloc()->adminUrl);
            return;
        }

        if (null !== $uid && $uid > 0) {
            if (!Auth::completeLogin($uid, $kookId, $settings, self::clientIp())) {
                self::renderLoginPage($settings, [
                    'error' => '站点所有者登录失败，请稍后再试。',
                    'step' => 'identity',
                ]);
                return;
            }
            self::redirect((string) Options::alloc()->adminUrl);
            return;
        }

        if (!Auth::completeGuestLogin($kookId, $settings, self::clientIp())) {
            self::renderLoginPage($settings, [
                'error' => 'KOOK 登录失败。',
                'step' => 'identity',
            ]);
            return;
        }

        self::redirect((string) Options::alloc()->siteUrl);
    }

    /**
     * 自动保存 AJAX 端点（admin/index.php?kook_save=1），在任何后台 HTML 之前退出。
     */
    private static function handleAjaxSave(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();
            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有保存设置的权限']);
            }

            $options = Options::alloc();
            $endpoint = self::saveEndpoint();
            $expected = Security::alloc()->getToken($endpoint);
            $raw = file_get_contents('php://input');
            $payload = json_decode((string) $raw, true);

            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $provided = isset($payload['_']) ? (string) $payload['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效，请刷新页面后重试']);
            }

            if (!self::sameOrigin($options)) {
                self::json(['ok' => false, 'error' => '来源校验失败']);
            }

            $incoming = $payload['settings'] ?? null;

            if (!is_array($incoming)) {
                self::json(['ok' => false, 'error' => '无效的设置数据']);
            }

            $incoming = self::preserveEmptySecrets($incoming);
            $errors = [];
            $settings = Settings::normalize($incoming, true, $errors);

            if (!empty($errors)) {
                self::json([
                    'ok' => false,
                    'error' => (string) reset($errors),
                    'errors' => $errors,
                ]);
            }

            Settings::save($settings);
            $bot = null;

            if ('' !== trim((string) ($settings['botToken'] ?? ''))) {
                try {
                    $bot = BotRunner::start();
                } catch (\Throwable $e) {
                    $bot = ['ok' => false, 'error' => $e->getMessage()];
                }
            }

            self::json([
                'ok' => true,
                'savedAt' => date('H:i:s'),
                'version' => Settings::VERSION,
                'bot' => $bot,
            ]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '保存失败，请稍后重试']);
        }
    }

    /**
     * 通用图片上传：文件先由机器人上传到 KOOK 资产库，再返回 KOOK CDN 地址。
     * LOGO 以及后续需要图片的设置都复用这个入口。
     */
    private static function handleAssetUpload(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有上传权限']);
            }

            $options = Options::alloc();
            $endpoint = self::uploadEndpoint();
            $expected = Security::alloc()->getToken($endpoint);
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效，请刷新页面后重试']);
            }

            if (!self::sameOrigin($options)) {
                self::json(['ok' => false, 'error' => '来源校验失败']);
            }

            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                self::json(['ok' => false, 'error' => '没有收到图片文件']);
            }

            $file = $_FILES['file'];

            if (!empty($file['error'])) {
                self::json(['ok' => false, 'error' => '图片上传失败（错误码 ' . (int) $file['error'] . '）']);
            }

            $tmp = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);

            if ('' === $tmp || !is_uploaded_file($tmp)) {
                self::json(['ok' => false, 'error' => '图片临时文件无效']);
            }

            if ($size <= 0 || $size > 4 * 1024 * 1024) {
                self::json(['ok' => false, 'error' => '图片不能超过 4MB']);
            }

            $fileName = (string) ($file['name'] ?? 'image');
            $mime = '';
            $info = @getimagesize($tmp);

            if (is_array($info) && !empty($info['mime'])) {
                $mime = (string) $info['mime'];
            }

            if ('' === $mime && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);

                if (false !== $finfo) {
                    $detected = finfo_file($finfo, $tmp);
                    finfo_close($finfo);

                    if (is_string($detected)) {
                        $mime = $detected;
                    }
                }
            }

            if (preg_match('/\.svg$/i', $fileName)) {
                $head = (string) file_get_contents($tmp, false, null, 0, 512);

                if (false !== stripos($head, '<svg') || false !== stripos($head, '<?xml')) {
                    $mime = 'image/svg+xml';
                }
            }

            if ('' === $mime) {
                $mime = (string) ($file['type'] ?? '');
            }

            if ('' === $mime || 'application/octet-stream' === strtolower($mime)) {
                $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
                $mime = [
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'svg' => 'image/svg+xml',
                    'avif' => 'image/avif',
                    'bmp' => 'image/bmp',
                ][$ext] ?? $mime;
            }

            $allowed = [
                'image/png',
                'image/jpeg',
                'image/jpg',
                'image/gif',
                'image/webp',
                'image/svg+xml',
                'image/avif',
                'image/bmp',
            ];

            if (!in_array(strtolower($mime), $allowed, true)) {
                self::json(['ok' => false, 'error' => '仅支持 PNG / JPEG / GIF / WebP / SVG 图片']);
            }

            $settings = Settings::load();
            $botToken = trim((string) ($settings['botToken'] ?? ''));

            if ('' === $botToken) {
                self::json(['ok' => false, 'error' => '请先配置机器人 Token']);
            }

            $client = new KookClient($botToken);
            $assetUrl = $client->uploadAsset($tmp, (string) ($file['name'] ?? 'image'), $mime);
            $channelId = trim((string) ($settings['uploadChannelId'] ?? ''));
            $ownerId = trim((string) ($settings['ownerKookId'] ?? ''));
            $url = $client->publishImage($assetUrl, $channelId, $ownerId);
            self::json(['ok' => true, 'url' => $url]);
        } catch (KookException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '图片上传失败，请稍后重试']);
        }
    }

    public static function uploadEndpoint(): string
    {
        return Common::url('index.php?kook_upload=1', (string) Options::alloc()->adminUrl);
    }

    public static function botEndpoint(): string
    {
        return Common::url('index.php?kook_bot=1', (string) Options::alloc()->adminUrl);
    }

    public static function claimEndpoint(): string
    {
        return Common::url('index.php?kook_claim=1', (string) Options::alloc()->adminUrl);
    }

    public static function directoryEndpoint(): string
    {
        return Common::url('index.php?kook_directory=1', (string) Options::alloc()->adminUrl);
    }

    public static function welcomeEndpoint(): string
    {
        return Common::url('index.php?kook_welcome=1', (string) Options::alloc()->adminUrl);
    }

    public static function aiEndpoint(): string
    {
        return Common::url('index.php?kook_ai=1', (string) Options::alloc()->adminUrl);
    }

    public static function updateEndpoint(): string
    {
        return Common::url('index.php?kook_update=1', (string) Options::alloc()->adminUrl);
    }

    public static function presenceEndpoint(): string
    {
        return Common::url('index.php?kook_presence=1', (string) Options::alloc()->adminUrl);
    }

    public static function inviteEndpoint(): string
    {
        return Common::url('index.php?kook_invite=1', (string) Options::alloc()->adminUrl);
    }

    private static function handleDirectory(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有读取权限']);
            }

            $expected = Security::alloc()->getToken(self::directoryEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效，请刷新页面后重试']);
            }

            if (!self::sameOrigin(Options::alloc())) {
                self::json(['ok' => false, 'error' => '来源校验失败']);
            }

            $settings = Settings::load();
            $token = trim((string) ($settings['botToken'] ?? ''));
            $guildId = trim((string) ($_POST['guild_id'] ?? ($settings['guildId'] ?? '')));

            if ('' === $token) {
                self::json(['ok' => false, 'error' => '请先填写机器人 Token']);
            }

            $client = new KookClient($token);
            $client->setTimeout(10);

            if ('' === $guildId) {
                self::json(['ok' => true, 'guilds' => $client->guildList()]);
            }

            self::json(['ok' => true, 'directory' => $client->directory($guildId)]);
        } catch (KookException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '读取服务器信息失败']);
        }
    }

    private static function handleWelcomeTest(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有权限']);
            }

            $expected = Security::alloc()->getToken(self::welcomeEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效']);
            }

            $settings = Settings::load();
            $testUserId = trim((string) ($settings['ownerKookId'] ?? ''));

            if ('' === $testUserId) {
                self::json(['ok' => false, 'error' => '请先填写 KOOK 账号映射中的用户 ID']);
            }

            Welcome::send($settings, $testUserId, (string) ($settings['guildId'] ?? ''));
            self::json(['ok' => true, 'message' => '测试欢迎卡已发送']);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function handleAi(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有权限']);
            }

            $expected = Security::alloc()->getToken(self::aiEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效']);
            }

            $action = (string) ($_POST['action'] ?? '');

            if ('get' === $action) {
                $current = Settings::load();
                self::json(['ok' => true, 'settings' => [
                    'aiEnabled' => (string) ($current['aiEnabled'] ?? '0'),
                    'aiApiKey' => (string) ($current['aiApiKey'] ?? ''),
                    'aiBaseUrl' => (string) ($current['aiBaseUrl'] ?? 'https://api.deepseek.com'),
                    'aiModel' => (string) ($current['aiModel'] ?? 'deepseek-chat'),
                    'aiSystemPrompt' => (string) ($current['aiSystemPrompt'] ?? ''),
                    'aiTemperature' => (string) ($current['aiTemperature'] ?? '0.8'),
                    'aiMaxTokens' => (string) ($current['aiMaxTokens'] ?? '180'),
                    'aiHistoryLimit' => (string) ($current['aiHistoryLimit'] ?? '6'),
                    'aiChannelId' => (string) ($current['aiChannelId'] ?? ''),
                    'aiReplyAll' => (string) ($current['aiReplyAll'] ?? '0'),
                ]]);
            }

            if ('save' === $action) {
                $current = Settings::load();

                foreach ([
                    'aiEnabled', 'aiApiKey', 'aiBaseUrl', 'aiModel', 'aiSystemPrompt',
                    'aiTemperature', 'aiMaxTokens', 'aiHistoryLimit', 'aiChannelId', 'aiReplyAll',
                ] as $key) {
                    if (isset($_POST[$key])) {
                        $current[$key] = (string) $_POST[$key];
                    }
                }

                Settings::save($current);
                self::json(['ok' => true, 'saved' => true]);
            }

            if (!class_exists(AiChatService::class)) {
                self::json(['ok' => false, 'error' => 'AI 类文件未上传完整']);
            }

            if ('logs' === $action) {
                self::json(['ok' => true, 'logs' => AiChatService::tailLogs(100)]);
            }

            $settings = Settings::load();
            $token = trim((string) ($settings['botToken'] ?? ''));
            $service = new AiChatService($settings, $token);
            $reply = $service->test((string) ($_POST['prompt'] ?? ''));
            self::json(['ok' => true, 'reply' => $reply]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function handleUpdate(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有更新权限']);
            }

            $expected = Security::alloc()->getToken(self::updateEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效']);
            }

            $action = (string) ($_POST['action'] ?? 'check');

            if ('install' === $action) {
                $result = \TypechoPlugin\MoePanel\Updater::install(
                    (string) ($_POST['url'] ?? ''),
                    (string) ($_POST['digest'] ?? '')
                );
                self::json(['ok' => true, 'result' => $result]);
            }

            self::json(['ok' => true, 'result' => \TypechoPlugin\MoePanel\Updater::check(true)]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function handlePresence(): void
    {
        if (!class_exists(Presence::class)) {
            self::json(['ok' => false, 'error' => 'Presence 模块未安装']);
        }

        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有操作权限']);
            }

            $expected = Security::alloc()->getToken(self::presenceEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效']);
            }

            $settings = Settings::load();
            $action = (string) ($_POST['action'] ?? 'start');
            $type = (string) ($_POST['type'] ?? 'game');

            if ('stop' === $action) {
                Presence::stop($settings, $type);
                self::json(['ok' => true]);
            }

            Presence::start(
                $settings,
                $type,
                (string) ($_POST['game'] ?? ''),
                (string) ($_POST['song'] ?? ''),
                (string) ($_POST['singer'] ?? ''),
                (string) ($_POST['software'] ?? 'cloudmusic')
            );
            self::json(['ok' => true]);
        } catch (KookException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '设置状态失败']);
        }
    }

    private static function handleInvite(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有操作权限']);
            }

            $expected = Security::alloc()->getToken(self::inviteEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效']);
            }

            $settings = Settings::load();
            $token = trim((string) ($settings['botToken'] ?? ''));

            if ('' === $token) {
                self::json(['ok' => false, 'error' => '请先填写机器人 Token']);
            }

            $client = new KookClient($token);
            $client->setTimeout(8);
            $action = (string) ($_POST['action'] ?? 'list');
            $guildId = trim((string) ($_POST['guild_id'] ?? ($settings['guildId'] ?? '')));
            $channelId = trim((string) ($_POST['channel_id'] ?? ''));

            if ('create' === $action) {
                $result = $client->inviteCreate(
                    $guildId,
                    $channelId,
                    (int) ($_POST['duration'] ?? 604800),
                    (int) ($_POST['times'] ?? -1)
                );
                self::json(['ok' => true, 'url' => (string) ($result['data']['url'] ?? '')]);
            }

            if ('delete' === $action) {
                $client->inviteDelete((string) ($_POST['url_code'] ?? ''), $guildId, $channelId);
                self::json(['ok' => true]);
            }

            self::json(['ok' => true, 'items' => $client->inviteList($guildId, $channelId)]);
        } catch (KookException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '邀请操作失败']);
        }
    }

    private static function handleClaimPublish(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有发布权限']);
            }

            $expected = Security::alloc()->getToken(self::claimEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效，请刷新页面后重试']);
            }

            if (!self::sameOrigin(Options::alloc())) {
                self::json(['ok' => false, 'error' => '来源校验失败']);
            }

            $settings = Settings::load();

            if (isset($_POST['channel_id'])) {
                $settings['claimChannelId'] = preg_replace('/\D+/', '', (string) $_POST['channel_id']);
            }

            if (isset($_POST['roles'])) {
                $settings['claimRoles'] = (string) $_POST['roles'];
            }

            $result = RoleClaim::publish($settings);
            self::json(['ok' => true, 'messageId' => (string) ($result['data']['msg_id'] ?? '')]);
        } catch (KookException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => '发布失败，请稍后重试']);
        }
    }

    private static function handleBot(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            self::json(['ok' => false, 'error' => '请求方法不受支持']);
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $user = User::alloc();

            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                self::json(['ok' => false, 'error' => '没有操作权限']);
            }

            $expected = Security::alloc()->getToken(self::botEndpoint());
            $provided = isset($_POST['_']) ? (string) $_POST['_'] : '';

            if ('' === $provided || !hash_equals($expected, $provided)) {
                self::json(['ok' => false, 'error' => '安全令牌无效，请刷新页面后重试']);
            }

            if (!self::sameOrigin(Options::alloc())) {
                self::json(['ok' => false, 'error' => '来源校验失败']);
            }

            $action = strtolower((string) ($_POST['action'] ?? 'status'));

            if ('start' === $action) {
                $result = BotRunner::start();
            } elseif ('stop' === $action) {
                $result = BotRunner::stop();
            } else {
                $result = BotRunner::status();
            }

            self::json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $error) {
            self::json(['ok' => false, 'error' => $error->getMessage()]);
        }
    }

    private static function renderLoginPage(array $settings, array $extra): void
    {
        $options = Options::alloc();
        $fatal = $extra['fatal'] ?? null;
        $csrf = '';
        $oauthEnabled = false;
        $codeEnabled = false;
        $oauthUrl = '';
        $warnings = [];

        if (null === $fatal) {
            $csrf = bin2hex(random_bytes(32));
            self::setCookie('kookauth_csrf', $csrf, time() + 3600);

            $availability = self::methodAvailability($settings);
            $oauthEnabled = (bool) $availability['oauth'];
            $codeEnabled = (bool) $availability['code'];

            if ($oauthEnabled) {
                $state = bin2hex(random_bytes(24));
                self::setCookie('kookauth_state', $state, time() + 600);
                $client = new KookClient((string) ($settings['botToken'] ?? ''));
                $oauthUrl = $client->authorizeUrl(
                    (string) $settings['clientId'],
                    self::callbackUrl(),
                    $state
                );
            } else {
                self::deleteCookie('kookauth_state');
            }

            if ('1' === (string) $settings['oauthLogin'] && !$oauthEnabled) {
                $warnings[] = 'OAuth 登录已启用，但 Client ID / Client Secret 尚未填写。';
            }

            if ('1' === (string) $settings['codeLogin'] && !$codeEnabled) {
                $warnings[] = '验证码登录已启用，但机器人 Token 尚未填写。';
            }

            if (!self::hasMapping($settings)) {
                $warnings[] = '未配置所有者 KOOK ID；KOOK 登录后将仅能留言，不能进入后台。';
            }
        }

        $context = array_merge([
            'settings' => $settings,
            'options' => $options,
            'error' => '',
            'notice' => '',
            'identity' => '',
            'step' => 'identity',
            'flow' => '',
            'masked' => '',
            'csrf' => $csrf,
            'oauthEnabled' => $oauthEnabled,
            'codeEnabled' => $codeEnabled,
            'oauthUrl' => $oauthUrl,
            'fatal' => $fatal,
            'warnings' => $warnings,
            'actionUrl' => (string) $options->loginUrl,
            'fallbackUrl' => (string) $options->loginUrl . '?kook_password=1',
            'fallback' => '1' === (string) ($settings['passwordFallback'] ?? '1'),
        ], $extra);

        if (!empty($extra['warnings']) && is_array($extra['warnings'])) {
            $context['warnings'] = array_merge($warnings, $extra['warnings']);
        }

        self::$loginContext = $context;
    }

    private static function renderFatal(array $settings, string $title, string $message): void
    {
        self::renderLoginPage($settings, [
            'fatal' => ['title' => $title, 'message' => $message],
            'oauthEnabled' => false,
            'codeEnabled' => false,
            'warnings' => [],
        ]);
    }

    /**
     * @return array{oauth:bool,code:bool,any:bool}
     */
    private static function methodAvailability(array $settings): array
    {
        $oauth = '1' === (string) ($settings['oauthLogin'] ?? '0')
            && '' !== trim((string) ($settings['clientId'] ?? ''))
            && '' !== trim((string) ($settings['clientSecret'] ?? ''));

        $code = '1' === (string) ($settings['codeLogin'] ?? '0')
            && '' !== trim((string) ($settings['botToken'] ?? ''));

        return ['oauth' => $oauth, 'code' => $code, 'any' => $oauth || $code];
    }

    private static function hasMapping(array $settings): bool
    {
        return count(Auth::mapping($settings)) > 0;
    }

    private static function missingConfigMessage(array $settings, array $availability): string
    {
        $reasons = [];

        if (!$availability['any']) {
            $reasons[] = 'OAuth 与验证码登录均不可用：请填写 Client ID / Client Secret，或机器人 Token。';
        }

        if (!self::hasMapping($settings)) {
            $reasons[] = '尚未配置所有者映射；KOOK 登录仍可用于留言。';
        }

        return implode(' ', $reasons) ?: '请检查 MoePanel 的 KOOK 设置。';
    }

    public static function callbackUrl(): string
    {
        return Common::url('login.php?kook_oauth=callback', (string) Options::alloc()->adminUrl);
    }

    public static function saveEndpoint(): string
    {
        return Common::url('index.php?kook_save=1', (string) Options::alloc()->adminUrl);
    }

    private static function redirect(string $url): void
    {
        $response = Response::getInstance();
        $response->setStatus(302);
        $response->setHeader('Location', Common::safeUrl($url));
        $response->respond();
    }

    /**
     * 空白的 Secret / Bot Token 视为“保持已保存值”，避免密码框被浏览器
     * 或密码管理器清空时误删密钥。如需彻底清除，请先关闭对应登录方式，
     * 或直接替换为新的值。
     */
    private static function preserveEmptySecrets(array $incoming): array
    {
        $existing = Settings::load();

        foreach (['clientSecret', 'botToken'] as $key) {
            $provided = array_key_exists($key, $incoming) ? trim((string) $incoming[$key]) : '';
            $current = trim((string) ($existing[$key] ?? ''));

            if ('' === $provided && '' !== $current) {
                $incoming[$key] = $current;
            }
        }

        return $incoming;
    }

    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; "
            . "script-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'none'; "
            . "form-action 'self'; frame-ancestors 'none'"
        );
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        $options = [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (!headers_sent()) {
            setcookie($name, $value, $options);
        }

        $_COOKIE[$name] = $value;
    }

    private static function deleteCookie(string $name): void
    {
        if (!headers_sent()) {
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => self::cookiePath(),
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        unset($_COOKIE[$name]);
    }

    private static function clearAuthCookies(): void
    {
        self::deleteCookie('kookauth_csrf');
        self::deleteCookie('kookauth_state');
    }

    private static function cookiePath(): string
    {
        $path = parse_url((string) Options::alloc()->adminUrl, PHP_URL_PATH);
        $path = is_string($path) && '' !== $path ? $path : '/';
        $trimmed = trim($path, '/');

        return '' === $trimmed ? '/' : '/' . $trimmed . '/';
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return ('on' === strtolower((string) $https) || '1' === (string) $https)
            || 443 === (int) ($_SERVER['SERVER_PORT'] ?? 0);
    }

    private static function clientIp(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        return '' !== $ip ? substr($ip, 0, 64) : '0.0.0.0';
    }

    private static function sameOrigin(Options $options): bool
    {
        $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if ('' === $source) {
            $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }

        if ('' === $source) {
            return true;
        }

        $host = parse_url($source, PHP_URL_HOST);
        $port = parse_url($source, PHP_URL_PORT);
        $siteHost = parse_url((string) $options->siteUrl, PHP_URL_HOST);
        $sitePort = parse_url((string) $options->siteUrl, PHP_URL_PORT);

        if (!is_string($host) || '' === $host || !is_string($siteHost) || '' === $siteHost) {
            return true;
        }

        if (0 !== strcasecmp($host, $siteHost)) {
            return false;
        }

        if (null !== $port && null !== $sitePort && (int) $port !== (int) $sitePort) {
            return false;
        }

        return true;
    }

    private static function json(array $payload): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
