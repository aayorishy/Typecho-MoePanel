<?php

namespace TypechoPlugin\MoePanel;

use Typecho\Common;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;
use Widget\Security;

require_once __DIR__ . '/Panel.php';
require_once __DIR__ . '/Updater.php';
require_once __DIR__ . '/Kook/Settings.php';
require_once __DIR__ . '/Kook/KookClient.php';
require_once __DIR__ . '/Kook/Auth.php';
require_once __DIR__ . '/Kook/Controller.php';
require_once __DIR__ . '/Kook/CardMessage.php';
require_once __DIR__ . '/Kook/PostNotifier.php';

foreach (['Presence', 'DeepSeekClient', 'AiConversationStore', 'AiChatService'] as $optionalClass) {
    $optionalFile = __DIR__ . '/Kook/' . $optionalClass . '.php';

    if (is_file($optionalFile)) {
        require_once $optionalFile;
    }
}
require_once __DIR__ . '/Kook/BotRunner.php';
require_once __DIR__ . '/Kook/RoleClaim.php';

/**
 * MoePanel —— 面向 Typecho 的 KOOK 一体化可视化控制台。
 *
 * 在不修改 Typecho 核心的前提下，为后台提供统一的设计语言，并集成
 * KOOK 登录、验证码、卡片消息、身份组领取、欢迎消息、文章推送、
 * GitHub 推送、机器人状态与自动更新等功能。
 *
 * 代码结构：
 * - Panel.php：后台界面转换与模板渲染
 * - Kook/：KOOK 登录、消息与机器人能力
 * - templates/：控制台、登录页与侧边栏模板
 * - assets/：样式、脚本与字体
 *
 * @package MoePanel
 * @author ayorishy
 * @version 1.0.0
 * @link https://github.com/aayorishy/Typecho-MoePanel
 * @since 1.0.0
 */
class Plugin implements PluginInterface
{
    private const VERSION = '1.0.0';

    public static function version(): string
    {
        return self::VERSION;
    }
    private const THEME_KEY = 'studio-panel-theme';

    private static bool $buffering = false;
    private static int $bufferLevel = 0;

    public static function activate()
    {
        Kook\Settings::ensureTable();
        Kook\Auth::ensureTable();
        Kook\PostNotifier::ensureTable();
        Kook\Settings::bootstrapActivation();

        TypechoPlugin::factory('admin/header.php')->header = [__CLASS__, 'header'];
        // begin_0 runs before the shell's begin (weight 0), so the standalone
        // KOOK login page can be sent before any output buffering starts.
        TypechoPlugin::factory('admin/common.php')->begin_0 = [Kook\Controller::class, 'handleAdmin'];
        TypechoPlugin::factory('admin/common.php')->begin = [__CLASS__, 'begin'];
        TypechoPlugin::factory('admin/footer.php')->end_1000 = [__CLASS__, 'finish'];
        TypechoPlugin::factory('Widget\Contents\Post\Edit')->finishPublish = [__CLASS__, 'finishPublish'];
        TypechoPlugin::factory('Widget\Archive')->header = [__CLASS__, 'frontendHeader'];
    }

    public static function finishPublish($contents, $post): void
    {
        Kook\PostNotifier::finishPublish($contents, $post);
    }

    public static function frontendHeader($header, $archive): void
    {
        try {
            $base = rtrim((string) Options::alloc()->pluginUrl, '/') . '/MoePanel/assets';
        } catch (\Throwable $exception) {
            return;
        }

        $font = $base . '/fonts/AaJiangShouZhangShouXieTi-2.ttf';

        echo '<style id="sp-site-font">'
            . '@font-face{font-family:"AaJiangShou";src:url("' . self::e($font) . '") format("truetype");font-display:swap;}'
            . 'body,button,input,textarea,select,h1,h2,h3,h4,h5,h6,p,a,li,blockquote{font-family:"AaJiangShou","PingFang SC","Microsoft YaHei",sans-serif !important;}'
            . '</style>';
    }

    public static function deactivate()
    {
        Kook\Settings::importOptionToStore();
    }

    public static function config(Form $form)
    {
        $settings = Kook\Settings::load();
        $callback = Kook\Controller::callbackUrl();

        $form->addInput(new Radio(
            'enable',
            ['1' => '启用', '0' => '关闭'],
            $settings['enable'],
            '启用 KOOK 登录',
            '默认关闭。启用后 <code>admin/login.php</code> 会显示 KOOK 登录页；'
            . '<code>?kook_password=1</code> 始终保留为应急密码登录入口。'
        ));

        $form->addInput(new Radio(
            'oauthLogin',
            ['1' => '允许', '0' => '禁止'],
            $settings['oauthLogin'],
            'OAuth 登录',
            '显示“使用 KOOK OAuth 登录”按钮，需要 Client ID 与 Client Secret。'
        ));

        $form->addInput(new Radio(
            'codeLogin',
            ['1' => '允许', '0' => '禁止'],
            $settings['codeLogin'],
            '验证码登录',
            '输入 KOOK 昵称#编号或用户 ID，由机器人发送 6 位验证码私信。'
        ));

        $form->addInput(new Radio(
            'passwordFallback',
            ['1' => '允许', '0' => '禁止'],
            $settings['passwordFallback'],
            '密码回退入口',
            '在登录页显示“使用管理员密码登录”链接。即使关闭该链接，'
            . '<code>?kook_password=1</code> 仍然可用，避免把自己锁在后台之外。'
        ));

        $form->addInput(new Radio(
            'successCard',
            ['1' => '开启', '0' => '关闭'],
            $settings['successCard'],
            '登录成功通知',
            '登录成功后额外发送一张 #FFCDCC 卡片私信，包含时间与 IP。发送失败不会影响登录。'
        ));

        $form->addInput(new Radio(
            'syncDisplayName',
            ['1' => '同步', '0' => '仅后台显示'],
            $settings['syncDisplayName'],
            'KOOK 昵称同步',
            '登录后把 KOOK 昵称写回 Typecho 显示名，文章/评论作者名也会一致；'
            . '选择“仅后台显示”时只替换 MoePanel 侧边栏与概要页。'
        ));

        $form->addInput(new Text(
            'clientId',
            null,
            $settings['clientId'],
            'KOOK Client ID',
            '在 KOOK 开发者后台创建应用后获取。OAuth 回调地址必须填写：'
            . '<br><code>' . htmlspecialchars($callback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code><br>'
            . '请将该地址原样加入 KOOK 应用的 OAuth 重定向 URI 白名单。'
        ));

        $form->addInput(new Text(
            'clientSecret',
            null,
            $settings['clientSecret'],
            'KOOK Client Secret',
            '仅保存在站点数据库中，不会出现在前台登录页。'
        ));

        $form->addInput(new Text(
            'botToken',
            null,
            $settings['botToken'],
            '机器人 Token',
            'KOOK 机器人连接 Token。请勿带 <code>Bot </code> 前缀，插件会自动添加。'
        ));

        $form->addInput(new Text(
            'guildId',
            null,
            $settings['guildId'],
            'KOOK 服务器 (Guild) ID',
            '用于把“昵称#编号”解析为具体成员；直接输入数字用户 ID 时可不填。'
        ));

        $form->addInput(new Text(
            'ownerKookId',
            null,
            $settings['ownerKookId'],
            '所有者 KOOK 用户 ID',
            '允许登录的 KOOK 用户 ID（数字）。该账号会映射到下面的 Typecho UID。'
        ));

        // 兼容旧版本配置里的 extraMap 键：表单仍提供隐藏输入，避免
        // Typecho 配置页遍历旧 option 时找不到字段而报 500；保存后该键会被清除。
        $legacyMap = new Text('extraMap', null, '', '', '');
        $legacyMap->input->setAttribute('type', 'hidden');
        $legacyMap->container->setAttribute('class', 'typecho-option sp-ks-legacy');
        $form->addInput($legacyMap);

        $form->addInput(new Text(
            'ownerUid',
            null,
            $settings['ownerUid'],
            '所有者 Typecho UID',
            '所有者登录后使用的 Typecho 用户 UID，默认 <code>1</code>。'
        ));

        $form->addInput(new Text(
            'cardColor',
            null,
            $settings['cardColor'],
            '卡片颜色',
            '默认 <code>#FFCDCC</code>。只影响卡片 color，不会设置 theme。'
        ));

        $form->addInput(new Text(
            'verifyCardTitle',
            null,
            $settings['verifyCardTitle'],
            '验证码卡片标题',
            'KOOK 卡片 header 文本，最多 100 字。'
        ));

        $verifyContent = new Textarea(
            'verifyCardContent',
            null,
            $settings['verifyCardContent'],
            '验证码卡片正文',
            '支持 KMarkdown。可用变量：<code>{code}</code> 验证码、<code>{minutes}</code> 有效分钟数、<code>{ip}</code>、<code>{time}</code>。'
        );
        $verifyContent->input->setAttribute('rows', '6');
        $form->addInput($verifyContent);

        $verifyContext = new Textarea(
            'verifyCardContext',
            null,
            $settings['verifyCardContext'],
            '验证码卡片备注',
            '显示在卡片底部，每行一条纯文本。支持 <code>{ip}</code>、<code>{time}</code>。'
        );
        $verifyContext->input->setAttribute('rows', '3');
        $form->addInput($verifyContext);

        $form->addInput(new Text(
            'successCardTitle',
            null,
            $settings['successCardTitle'],
            '成功卡片标题',
            '登录成功通知卡片的 header 文本。'
        ));

        $successContent = new Textarea(
            'successCardContent',
            null,
            $settings['successCardContent'],
            '成功卡片正文',
            '支持 KMarkdown。可用变量：<code>{site}</code> 站点名、<code>{time}</code>、<code>{ip}</code>。'
        );
        $successContent->input->setAttribute('rows', '5');
        $form->addInput($successContent);

        $successContext = new Textarea(
            'successCardContext',
            null,
            $settings['successCardContext'],
            '成功卡片备注',
            '显示在卡片底部，每行一条纯文本。支持 <code>{site}</code>、<code>{time}</code>、<code>{ip}</code>。'
        );
        $successContext->input->setAttribute('rows', '3');
        $form->addInput($successContext);

        $form->addInput(new Text(
            'codeTtl',
            null,
            $settings['codeTtl'],
            '验证码有效期（秒）',
            '范围 120–1800，默认 600。'
        ));

        $form->addInput(new Text(
            'resendInterval',
            null,
            $settings['resendInterval'],
            '重发间隔（秒）',
            '同一 KOOK 账号的最短发送间隔，范围 15–600，默认 45。'
        ));

        $form->addInput(new Text(
            'maxAttempts',
            null,
            $settings['maxAttempts'],
            '最大尝试次数',
            '同一验证码允许的错误次数，范围 3–10，默认 5。'
        ));

        $form->addInput(new Text(
            'pageTitle',
            null,
            $settings['pageTitle'],
            '登录页标题',
            '登录卡片顶部标题，留空使用默认值。'
        ));

        $form->addInput(new Text(
            'pageSubtitle',
            null,
            $settings['pageSubtitle'],
            '登录页副标题',
            '登录卡片说明文字，留空使用默认值。'
        ));

        $form->addInput(new Text(
            'logoUrl',
            null,
            $settings['logoUrl'],
            '控制台 LOGO（浅色）',
            '图片地址，建议 1:1 或透明 PNG/SVG。可填绝对 URL，或从媒体库复制相对地址（以 / 开头）。留空时使用站点名称首字母。'
        ));

        // 只保留一套 LOGO；旧的 logoDarkUrl 以隐藏字段兼容旧配置，保存后清空。
        $legacyDarkLogo = new Text('logoDarkUrl', null, '', '', '');
        $legacyDarkLogo->input->setAttribute('type', 'hidden');
        $legacyDarkLogo->container->setAttribute('class', 'typecho-option sp-ks-legacy');
        $form->addInput($legacyDarkLogo);

        $form->addInput(new Text(
            'uploadChannelId',
            null,
            $settings['uploadChannelId'],
            '图片上传频道 ID',
            '机器人上传图片后会把图片发送到这个频道，并取回 KOOK <code>assets/...</code> 图片地址。留空时私信所有者。'
        ));

        $form->addInput(new Text(
            'welcomeChannelId',
            null,
            $settings['welcomeChannelId'],
            'KOOK 欢迎频道 ID',
            '新成员加入时发送欢迎卡片的目标频道。'
        ));







        $form->addInput(new Textarea(
            'welcomeGreeting',
            null,
            $settings['welcomeGreeting'],
            '欢迎文字',
            '新成员加入时发送的纯文字欢迎语，支持 {user}、{site}、{count}。'
        ));

        $form->addInput(new Text(
            'claimChannelId',
            null,
            $settings['claimChannelId'],
            '身份组领取频道 ID',
            '发布身份组领取卡片的频道。'
        ));

        $form->addInput(new Textarea(
            'claimRoles',
            null,
            $settings['claimRoles'],
            '可领取身份组',
            '每行一个：<code>角色ID|显示名|主题色</code>，例如 <code>123456|小猫咪|success</code>。'
        ));

        $form->addInput(new Text(
            'githubSecret',
            null,
            $settings['githubSecret'],
            'GitHub Webhook Secret',
            'GitHub 仓库 Webhook 里填写的 Secret，用于校验 X-Hub-Signature-256。'
        ));

        $form->addInput(new Text(
            'githubChannelId',
            null,
            $settings['githubChannelId'],
            'GitHub 推送通知频道 ID',
            '留空时使用图片上传频道；也可以单独指定一个频道。'
        ));

        $form->addInput(new Textarea(
            'githubCardNote',
            null,
            $settings['githubCardNote'],
            'GitHub 卡片附加说明',
            '支持 KMarkdown；留空时使用仓库描述。'
        ));

        $form->addInput(new Text(
            'postChannelId',
            null,
            $settings['postChannelId'],
            '文章推送频道 ID',
            '发布新文章后自动发送卡片到此频道。'
        ));

        $form->addInput(new Text(
            'postCardTitle',
            null,
            $settings['postCardTitle'],
            '文章推送卡片标题',
            '支持 {title}、{site}。'
        ));

        $form->addInput(new Textarea(
            'postCardBody',
            null,
            $settings['postCardBody'],
            '文章推送卡片正文',
            '支持 {title}、{summary}、{url}、{site}、{author}、{time}。'
        ));

        $form->addInput(new Radio(
            'aiEnabled',
            ['1' => '启用', '0' => '关闭'],
            $settings['aiEnabled'],
            '角色扮演',
            '启用后，KOOK 中 @机器人 即可开始角色扮演对话。'
        ));

        $form->addInput(new Text(
            'aiApiKey',
            null,
            $settings['aiApiKey'],
            'DeepSeek API Key',
            '在 DeepSeek 控制台创建。'
        ));

        $form->addInput(new Text(
            'aiBaseUrl',
            null,
            $settings['aiBaseUrl'],
            'DeepSeek API 地址',
            '默认 https://api.deepseek.com'
        ));

        $form->addInput(new Text(
            'aiModel',
            null,
            $settings['aiModel'],
            '模型名称',
            '例如 deepseek-chat 或 deepseek-reasoner。'
        ));

        $form->addInput(new Textarea(
            'aiSystemPrompt',
            null,
            $settings['aiSystemPrompt'],
            '角色设定 / System Prompt',
            '描述机器人的人格、语气、世界观和限制。'
        ));

        $form->addInput(new Text(
            'aiTemperature',
            null,
            $settings['aiTemperature'],
            'Temperature',
            '0 到 2，越高越有变化。'
        ));

        $form->addInput(new Text(
            'aiMaxTokens',
            null,
            $settings['aiMaxTokens'],
            '最大回复 Token',
            '建议 120 到 300。'
        ));

        $form->addInput(new Text(
            'aiHistoryLimit',
            null,
            $settings['aiHistoryLimit'],
            '上下文条数',
            '保留最近几条对话，建议 4 到 8。'
        ));

        $form->addInput(new Text(
            'aiChannelId',
            null,
            $settings['aiChannelId'],
            'AI 频道 ID',
            '开启「频道内自动回复」时必须填写；@机器人 不受此限制。'
        ));

        $form->addInput(new Radio(
            'aiReplyAll',
            ['1' => '开启', '0' => '关闭'],
            $settings['aiReplyAll'],
            '频道内自动回复',
            '开启后，AI 频道里的每条文字都会触发对话，无需 @机器人。'
        ));

        $form->addInput(new Textarea(
            'verifyCardJson',
            null,
            $settings['verifyCardJson'],
            '验证码卡片高级结构',
            '由可视化编辑器维护，通常无需手动修改。'
        ));

        $form->addInput(new Textarea(
            'successCardJson',
            null,
            $settings['successCardJson'],
            '登录成功卡片高级结构',
            '由可视化编辑器维护，通常无需手动修改。'
        ));

        $form->addInput(new Text(
            'accentColor',
            null,
            $settings['accentColor'],
            '主题主色',
            '十六进制颜色，例如 <code>#5b5bd6</code>。影响按钮、链接、选中态与焦点。'
        ));

        $form->addInput(new Text(
            'accentColor2',
            null,
            $settings['accentColor2'],
            '主题辅色',
            '用于渐变按钮和高亮，建议选择主色的浅一档颜色。'
        ));
    }

    public static function configHandle(array $settings, bool $isInit = false): bool
    {
        try {
            Kook\Settings::save(self::preserveSecrets($settings));
        } catch (\Throwable $e) {
            try {
                \Widget\Notice::alloc()->set('KOOK 设置保存失败：' . $e->getMessage(), 'error');
            } catch (\Throwable $e2) {
                // 忽略提示失败。
            }
        }

        return true;
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function header(string $header): string
    {
        $options = Options::alloc();
        $base = rtrim((string) $options->pluginUrl, '/') . '/MoePanel/assets';
        $adminCss = self::assetUrl($base, 'admin.css');
        $studioCss = self::assetUrl($base, 'studio.css');
        $js = self::assetUrl($base, 'panel.js');
        $spaJs = self::assetUrl($base, 'spa.js');

        $header .= "\n" . '<meta name="color-scheme" content="light dark">';
        $header .= "\n" . '<meta name="theme-color" content="#0b1120">';
        $header .= "\n" . '<script>' . self::themeInitScript() . '</script>';
        $header .= "\n" . '<link rel="stylesheet" href="' . self::e($adminCss) . '">';
        $header .= "\n" . '<link rel="stylesheet" href="' . self::e($studioCss) . '">';
        $header .= "\n" . self::accentStyle();
        $header .= "\n" . '<script defer src="' . self::e($js) . '"></script>';
        $header .= "\n" . '<script defer src="' . self::e($spaJs) . '"></script>';

        if (class_exists(Kook\Controller::class)) {
            $mediaEndpoint = Kook\Controller::uploadEndpoint();
            $header .= "\n" . '<script>window.MoePanelMedia=' . json_encode([
                'endpoint' => $mediaEndpoint,
                'token' => Security::alloc()->getToken($mediaEndpoint),
            ], JSON_UNESCAPED_SLASHES) . ';</script>';
        }

        if (self::isKookConfigPage()) {
            $kookCss = self::assetUrl($base, 'kook-settings.css');
            $kookJs = self::assetUrl($base, 'kook-settings.js');
            $endpoint = Kook\Controller::saveEndpoint();
            $uploadEndpoint = Kook\Controller::uploadEndpoint();
            $configData = [
                'endpoint' => $endpoint,
                'token' => Security::alloc()->getToken($endpoint),
                'uploadEndpoint' => $uploadEndpoint,
                'uploadToken' => Security::alloc()->getToken($uploadEndpoint),
                'botEndpoint' => Kook\Controller::botEndpoint(),
                'botToken' => Security::alloc()->getToken(Kook\Controller::botEndpoint()),
                'claimEndpoint' => Kook\Controller::claimEndpoint(),
                'claimToken' => Security::alloc()->getToken(Kook\Controller::claimEndpoint()),
                'presenceEndpoint' => Kook\Controller::presenceEndpoint(),
                'presenceToken' => Security::alloc()->getToken(Kook\Controller::presenceEndpoint()),
                'updateEndpoint' => Kook\Controller::updateEndpoint(),
                'updateToken' => Security::alloc()->getToken(Kook\Controller::updateEndpoint()),
                'aiEndpoint' => Kook\Controller::aiEndpoint(),
                'aiToken' => Security::alloc()->getToken(Kook\Controller::aiEndpoint()),
                'welcomeEndpoint' => Kook\Controller::welcomeEndpoint(),
                'welcomeToken' => Security::alloc()->getToken(Kook\Controller::welcomeEndpoint()),
                'version' => self::VERSION,
                'inviteEndpoint' => Kook\Controller::inviteEndpoint(),
                'inviteToken' => Security::alloc()->getToken(Kook\Controller::inviteEndpoint()),
                'directoryEndpoint' => Kook\Controller::directoryEndpoint(),
                'directoryToken' => Security::alloc()->getToken(Kook\Controller::directoryEndpoint()),
                'fields' => Kook\Settings::fieldNames(),
                'version' => Kook\Settings::VERSION,
                'siteTitle' => (string) Options::alloc()->title,
                'siteInitial' => Common::subStr((string) Options::alloc()->title, 0, 1, ''),
                'webhookUrl' => rtrim((string) Options::alloc()->pluginUrl, '/') . '/MoePanel/github-webhook.php',
            ];
            $json = json_encode(
                $configData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
                | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );

            $header .= "\n" . '<link rel="stylesheet" href="' . self::e($kookCss) . '">';
            $header .= "\n" . '<script>window.MoePanelKookSettings=' . $json . ';</script>';
            $header .= "\n" . '<script defer src="' . self::e($kookJs) . '"></script>';
        }

        return $header;
    }

    private static function accentStyle(): string
    {
        try {
            $settings = Kook\Settings::load();
        } catch (\Throwable $e) {
            $settings = Kook\Settings::defaults();
        }

        $accent = self::hexColor((string) ($settings['accentColor'] ?? ''), '#5b5bd6');
        $accent2 = self::hexColor((string) ($settings['accentColor2'] ?? ''), '#7c6cff');
        [$r, $g, $b] = self::rgb($accent);
        [$r2, $g2, $b2] = self::rgb($accent2);
        $activeInk = self::shade($accent, -0.28);
        $rgb = $r . ',' . $g . ',' . $b;
        $rgb2 = $r2 . ',' . $g2 . ',' . $b2;

        return '<style id="sp-accent-theme">'
            . ':root{--sp-accent:' . $accent . ';--sp-accent-2:' . $accent2 . ';--sp-accent-3:' . $accent2
            . ';--sp-accent-rgb:' . $rgb . ';--sp-accent-2-rgb:' . $rgb2
            . ';--sp-accent-soft:rgba(' . $rgb . ',.10);'
            . '--sp-sidebar-active:rgba(' . $r . ',' . $g . ',' . $b . ',.10);'
            . '--sp-sidebar-icon-active:' . $accent2 . ';--sp-sidebar-active-ink:' . $activeInk . '}'
            . 'html[data-sp-theme="dark"]{--sp-accent:' . $accent . ';--sp-accent-2:' . $accent2
            . ';--sp-accent-3:' . $accent2 . ';--sp-accent-soft:rgba(' . $r . ',' . $g . ',' . $b . ',.18);'
            . '--sp-sidebar-active:rgba(' . $r . ',' . $g . ',' . $b . ',.18);'
            . '--sp-sidebar-icon-active:' . $accent2 . ';--sp-sidebar-active-ink:#ffffff}'
            . '</style>';
    }

    private static function hexColor(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }

    private static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    private static function shade(string $hex, float $factor): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $target = $factor < 0 ? 0 : 255;
        $amount = abs($factor);
        $r = (int) round($r + ($target - $r) * $amount);
        $g = (int) round($g + ($target - $g) * $amount);
        $b = (int) round($b + ($target - $b) * $amount);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private static function isKookConfigPage(): bool
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'options-plugin.php'
            && 'MoePanel' === (string) ($_GET['config'] ?? '');
    }

    private static function preserveSecrets(array $incoming): array
    {
        $existing = Kook\Settings::load();

        foreach (['clientSecret', 'botToken'] as $key) {
            $provided = array_key_exists($key, $incoming) ? trim((string) $incoming[$key]) : '';
            $current = trim((string) ($existing[$key] ?? ''));

            if ('' === $provided && '' !== $current) {
                $incoming[$key] = $current;
            }
        }

        return $incoming;
    }

    /**
     * 兼容旧版本写入 Typecho plugins 表的 index.php:begin 回调。
     * 该方法现在只做占位，避免旧数据库回调指向已不存在的方法导致前台 500。
     */
    public static function onIndexBegin(): void
    {
    }

    public static function begin()
    {
        if (self::$buffering || PHP_SAPI === 'cli' || !function_exists('ob_start')) {
            return;
        }

        self::$bufferLevel = ob_get_level();
        self::$buffering = @ob_start();

        if (self::$buffering) {
            // A fallback for themes/plugins that close the footer early or
            // replace the footer include. The flag makes this idempotent.
            register_shutdown_function([__CLASS__, 'finish']);
        }
    }

    public static function finish()
    {
        if (!self::$buffering) {
            return;
        }

        self::$buffering = false;

        if (ob_get_level() <= self::$bufferLevel) {
            return;
        }

        // Another extension may have opened an untracked buffer. Collect
        // every buffer above our own level so the shell is still applied.
        $chunks = [];

        while (ob_get_level() > self::$bufferLevel) {
            $chunk = ob_get_clean();

            if ($chunk === false) {
                break;
            }

            $chunks[] = $chunk;
        }

        if ($chunks === []) {
            return;
        }

        echo Panel::render(implode('', array_reverse($chunks)));
    }

    private static function themeInitScript(): string
    {
        return '(function(){var d=document.documentElement,k="' . self::THEME_KEY . '",t="",c;'
            . 'try{t=localStorage.getItem(k)||""}catch(e){}'
            . 'if(t!=="dark"&&t!=="light"){c=document.cookie.match(/(?:^|; )studio_panel_theme=([^;]*)/);'
            . 'if(c){try{t=decodeURIComponent(c[1])}catch(e){t=""}}}'
            . 'if(t!=="dark"&&t!=="light"){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}'
            . 'd.setAttribute("data-sp-theme",t);d.style.colorScheme=t;d.classList.add("sp-js")}());';
    }

    private static function assetUrl(string $base, string $file): string
    {
        $path = __DIR__ . '/assets/' . $file;
        $version = self::VERSION . '.' . (is_file($path) ? (int) @filemtime($path) : 0);

        return $base . '/' . $file . '?v=' . $version;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// 发布动作走 /action/contents-post-edit，不会经过 admin/common.php:begin。
// 在插件文件加载时注册，确保“新增钩子后未重新激活”的站点也能推送。
\Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = [Plugin::class, 'finishPublish'];
