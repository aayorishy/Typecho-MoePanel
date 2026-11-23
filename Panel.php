<?php

namespace TypechoPlugin\MoePanel;

use Typecho\Common;
use Typecho\Request;
use Widget\Comments\Recent as RecentComments;
use Widget\Contents\Post\Recent as RecentPosts;
use Widget\Options;
use Widget\Stat;
use Widget\User;

/**
 * Turns the stock Typecho admin HTML into the MoePanel interface.
 *
 * This class deliberately contains no hook registration. It only transforms
 * markup and renders templates, which keeps Plugin.php small and makes the
 * presentation layer independent from the plugin lifecycle.
 */
class Panel
{
    public static function render(string $html): string
    {
        try {
            if ($html === '' || stripos($html, '<html') === false) {
                return $html;
            }

            $isAuth = strpos($html, 'typecho-login-wrap') !== false;
            $html = self::prepareBody($html, $isAuth);

            if ($isAuth) {
                return self::renderAuth($html, strpos($html, 'name="register"') !== false);
            }

            $html = self::renderChrome($html);
            $html = self::renamePageTitle($html);

            if (strpos($html, 'profile-avatar') !== false) {
                $html = self::syncProfilePage($html);
            }

            if (strpos($html, 'typecho-dashboard') !== false) {
                $html = self::renderDashboard($html);
            }

            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

            if (('write-post.php' === $script || 'write-page.php' === $script)
                && strpos($html, 'id="custom-field"') !== false) {
                $html = self::injectPageOptions($html);
            }

            if ('manage-comments.php' === $script && strpos($html, 'comment-author') !== false) {
                $html = self::syncCommentAuthors($html);
            }

            return $html;
        } catch (\Throwable $error) {
            @file_put_contents(
                sys_get_temp_dir() . '/moepanel-panel-error.log',
                '[' . date('Y-m-d H:i:s') . '] ' . get_class($error) . ': ' . $error->getMessage()
                . ' in ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL,
                FILE_APPEND
            );

            return $html;
        }
    }

    private static function prepareBody(string $html, bool $isAuth): string
    {
        $classes = $isAuth ? 'sp-admin sp-auth-page' : 'sp-admin';
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $page = strtolower(basename($script, '.php'));

        if ('' !== $page && 'login' !== $page && 'register' !== $page) {
            $classes .= ' sp-page-' . preg_replace('/[^a-z0-9-]+/', '-', $page);

            if (0 === strpos($page, 'options-') && 'options-plugin' !== $page) {
                $classes .= ' sp-page-options';
            }

            if ('write-post' === $page || 'write-page' === $page) {
                $classes .= ' sp-page-write';
            }

            if (0 === strpos($page, 'manage-')) {
                $classes .= ' sp-page-manage';
            }
        }

        if ('options-plugin.php' === $script && 'MoePanel' === (string) ($_GET['config'] ?? '')) {
            $classes .= ' sp-plugin-config';
        }

        return preg_replace_callback('~<body\b([^>]*)>~i', static function (array $match) use ($classes): string {
            $attrs = $match[1];

            if (preg_match('~class="([^"]*)"~i', $attrs, $classMatch)) {
                $attrs = preg_replace(
                    '~class="[^"]*"~i',
                    'class="' . self::e(trim($classMatch[1] . ' ' . $classes)) . '"',
                    $attrs,
                    1
                );
            } else {
                $attrs .= ' class="' . self::e($classes) . '"';
            }

            return '<body' . $attrs . '>';
        }, $html, 1) ?? $html;
    }

    private static function renderAuth(string $html, bool $isRegister): string
    {
        $options = Options::alloc();
        $request = Request::getInstance();
        $action = self::formAction($html);

        if ($action === '') {
            $action = $isRegister ? (string) $options->registerAction() : (string) $options->loginAction();
        }

        $name = self::inputValue($html, 'name');
        $mail = self::inputValue($html, 'mail');
        $referer = self::inputValue($html, 'referer');
        $rememberTag = self::inputTag($html, 'remember');
        $remember = $rememberTag !== '' && strpos(strtolower($rememberTag), 'checked') !== false;

        if ($referer === '') {
            $referer = self::e((string) $request->get('referer'));
        }

        $siteTitle = (string) $options->title;
        $initial = strtoupper(Common::subStr($siteTitle, 0, 1, ''));
        $initial = $initial !== '' ? $initial : 'A';
        $siteUrl = (string) $options->siteUrl;
        $design = self::studioSettings();
        $kook = null;

        if (!$isRegister && class_exists('TypechoPlugin\\MoePanel\\Kook\\Controller')) {
            $kook = \TypechoPlugin\MoePanel\Kook\Controller::consumeContext();
        }

        $kookSwitch = null;

        if (!$isRegister && !is_array($kook)) {
            $kookSwitch = self::kookSwitch();
        }

        if ($isRegister) {
            $links = '<span>已经有账号？ <a href="' . self::e((string) $options->adminUrl('login.php', true))
                . '">用户登录</a></span>';
        } elseif (is_array($kook)) {
            $links = '<span>访问站点 <a href="' . self::e($siteUrl) . '">' . self::e($siteTitle) . '</a></span>';

            if (!empty($kook['fallback'])) {
                $links = '<span><a href="' . self::e((string) $kook['fallbackUrl'])
                    . '">使用管理员密码登录</a></span>' . $links;
            }
        } elseif ($options->allowRegister) {
            $links = '<span>还没有账号？ <a href="' . self::e((string) $options->registerUrl)
                . '">用户注册</a></span>';
        } else {
            $links = '<span>访问站点 <a href="' . self::e($siteUrl) . '">' . self::e($siteTitle) . '</a></span>';
        }

        $custom = self::view('auth', [
            'isRegister' => $isRegister,
            'action' => $action,
            'name' => $name,
            'mail' => $mail,
            'referer' => $referer,
            'remember' => $remember,
            'siteTitle' => $siteTitle,
            'initial' => $initial,
            'siteUrl' => $siteUrl,
            'year' => date('Y'),
            'version' => (string) $options->version,
            'links' => $links,
            'kook' => $kook,
            'kookSwitch' => $kookSwitch,
            'logoUrl' => (string) ($design['logoUrl'] ?? ''),
            'logoDarkUrl' => (string) ($design['logoDarkUrl'] ?? ''),
        ]);

        $result = preg_replace('~<div class="typecho-login-wrap">.*?</div>\s*</div>~s', $custom, $html, 1);

        return $result ?? $html;
    }

    private static function renderChrome(string $html): string
    {
        $options = Options::alloc();
        $user = User::alloc();
        $date = date('Y.m.d');

        $html = preg_replace_callback(
            '~<header class="typecho-head-nav"[^>]*>(.*?)</header>~is',
            static function (array $match) use ($options, $user): string {
                return self::sidebar($match[1], $options, $user) . self::topbar($options, $user);
            },
            $html,
            1
        ) ?? $html;

        $html = preg_replace_callback(
            '~<div class="typecho-page-title">\s*<h2>(.*?)</h2>~is',
            static function (array $match) use ($date): string {
                return '<div class="typecho-page-title"><div class="sp-page-title">'
                    . '<p class="sp-eyebrow"><span class="sp-eyebrow-dot" aria-hidden="true"></span>'
                    . 'Workspace<span class="sp-eyebrow-line" aria-hidden="true"></span>'
                    . self::e($date) . '</p><h2>' . $match[1] . '</h2></div>';
            },
            $html,
            1
        ) ?? $html;

        $html = preg_replace_callback(
            '~<footer class="typecho-foot"[^>]*>.*?</footer>~is',
            static function () use ($options): string {
                return self::view('footer', [
                    'siteTitle' => (string) $options->title,
                    'version' => (string) $options->version,
                    'software' => (string) $options->software,
                    'year' => date('Y'),
                ]);
            },
            $html,
            1
        ) ?? $html;

        return $html;
    }

    private static function sidebar(string $nav, Options $options, User $user): string
    {
        $siteTitle = (string) $options->title;
        $initial = strtoupper(Common::subStr($siteTitle, 0, 1, ''));
        $initial = $initial !== '' ? $initial : 'A';
        $design = self::studioSettings();
        $profile = class_exists('TypechoPlugin\\MoePanel\\Kook\\Auth')
            ? \TypechoPlugin\MoePanel\Kook\Auth::profileForUid((int) $user->uid)
            : null;
        $userName = (string) $user->screenName;

        if (is_array($profile)) {
            $candidate = trim((string) ($profile['nickname'] ?? ''));

            if ('' === $candidate) {
                $candidate = trim((string) ($profile['username'] ?? ''));
            }

            if ('' !== $candidate) {
                $userName = $candidate;
            }
        }

        $userInitial = Common::subStr($userName, 0, 1, '');
        $avatarUrl = is_array($profile) ? (string) ($profile['avatar'] ?? '') : '';
        $lastLogin = '';

        if ($user->logged > 0) {
            $logged = new \Typecho\Date($user->logged);
            $lastLogin = $logged->word();
        }

        $nav = strtr($nav, [
            '概要' => '概览',
            '个人设置' => '设置',
            '永久链接' => '链接',
            '独立页面' => '页面',
            '撰写文章' => '文章',
            '创建页面' => '页面',
            '设置插件 MoePanel' => '功能',
            '设置插件 MoePanel' => '功能',
        ]);

        return self::view('sidebar', [
            'nav' => $nav,
            'siteTitle' => $siteTitle,
            'initial' => $initial,
            'userName' => $userName,
            'userInitial' => $userInitial,
            'avatarUrl' => $avatarUrl,
            'lastLogin' => $lastLogin,
            'adminUrl' => (string) $options->adminUrl,
            'profileUrl' => self::adminUrl('profile.php'),
            'siteUrl' => (string) $options->siteUrl,
            'logoutUrl' => (string) $options->logoutUrl,
            'logoUrl' => (string) ($design['logoUrl'] ?? ''),
            'logoDarkUrl' => (string) ($design['logoDarkUrl'] ?? ''),
        ]);
    }

    private static function topbar(Options $options, User $user): string
    {
        $weekdays = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

        return self::view('topbar', [
            'siteTitle' => (string) $options->title,
            'date' => date('Y.m.d') . ' · ' . $weekdays[(int) date('w')],
            'searchUrl' => self::adminUrl('manage-posts.php'),
            'writeUrl' => self::adminUrl('write-post.php'),
            'canWrite' => $user->pass('contributor', true),
        ]);
    }

    private static function renamePageTitle(string $html): string
    {
        $page = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'));
        $titles = [
            'profile' => '个人资料',
            'options-permalink' => '链接设置',
            'manage-pages' => '页面',
        ];

        if ('options-plugin' === $page && 'MoePanel' === (string) ($_GET['config'] ?? '')) {
            $titles[$page] = '功能';
        }

        if (!isset($titles[$page])) {
            return $html;
        }

        return preg_replace_callback(
            '~(<div class="typecho-page-title">.*?<h2>)(.*?)(</h2>)~s',
            static function (array $match) use ($titles, $page): string {
                return $match[1] . self::e($titles[$page]) . $match[3];
            },
            $html,
            1
        ) ?? $html;
    }

    private static function syncProfilePage(string $html): string
    {
        if (!class_exists('TypechoPlugin\\MoePanel\\Kook\\Auth')) {
            return $html;
        }

        $user = User::alloc();
        $profile = \TypechoPlugin\MoePanel\Kook\Auth::profileForUid((int) $user->uid);

        if (!is_array($profile)) {
            return $html;
        }

        $avatar = trim((string) ($profile['avatar'] ?? ''));
        $nickname = trim((string) ($profile['nickname'] ?? ''));

        if ('' !== $avatar) {
            $html = preg_replace(
                '~<img class="profile-avatar" src="[^"]*"~',
                '<img class="profile-avatar" src="' . self::e($avatar) . '" referrerpolicy="no-referrer"',
                $html,
                1
            ) ?? $html;
        }

        if ('' !== $nickname) {
            $html = preg_replace(
                '~(<img class="profile-avatar".*?</p>\s*)<h2>.*?</h2>~s',
                '$1<h2>' . self::e($nickname) . '</h2>',
                $html,
                1
            ) ?? $html;
        }

        return $html;
    }

    private static function renderDashboard(string $html): string
    {
        $options = Options::alloc();
        $user = User::alloc();
        $stat = Stat::alloc();
        $posts = RecentPosts::alloc('pageSize=6');
        $comments = RecentComments::alloc('pageSize=5');

        $isEditor = $user->pass('editor', true);
        $isAdmin = $user->pass('administrator', true);
        $canWrite = $user->pass('contributor', true);
        $profile = class_exists('TypechoPlugin\\MoePanel\\Kook\\Auth')
            ? \TypechoPlugin\MoePanel\Kook\Auth::profileForUid((int) $user->uid)
            : null;
        $displayName = (string) $user->screenName;

        if (is_array($profile)) {
            $candidate = trim((string) ($profile['nickname'] ?? ''));

            if ('' === $candidate) {
                $candidate = trim((string) ($profile['username'] ?? ''));
            }

            if ('' !== $candidate) {
                $displayName = $candidate;
            }
        }

        $studioSettings = Kook\Settings::load();
        $kookBot = [];
        $kookError = '';
        $kookToken = trim((string) ($studioSettings['botToken'] ?? ''));

        if ('' !== $kookToken) {
            try {
                $kookClient = new \TypechoPlugin\MoePanel\Kook\KookClient($kookToken);
                $kookClient->setTimeout(4);
                $kookBot = $kookClient->me();
            } catch (\Throwable $e) {
                $kookError = $e->getMessage();
            }
        }

        $kookReachable = [] !== $kookBot;
        $kookOnline = $kookReachable && !empty($kookBot['online']);

        if ($kookReachable && !array_key_exists('online', $kookBot) && !empty($kookBot['id'])) {
            try {
                $kookUser = $kookClient->userView((string) $kookBot['id'], (string) ($studioSettings['guildId'] ?? ''));
                $kookOnline = !empty($kookUser['online']);

                if (isset($kookUser['avatar']) && '' !== $kookUser['avatar']) {
                    $kookBot['avatar'] = $kookUser['avatar'];
                }
            } catch (\Throwable $e) {
                $kookError = $e->getMessage();
            }
        }

        $kook = [
            'enabled' => '1' === (string) ($studioSettings['enable'] ?? '0'),
            'configured' => '' !== $kookToken && '' !== trim((string) ($studioSettings['guildId'] ?? '')),
            'reachable' => $kookReachable,
            'online' => $kookOnline,
            'uploadReady' => '' !== trim((string) ($studioSettings['uploadChannelId'] ?? ''))
                || '' !== trim((string) ($studioSettings['ownerKookId'] ?? '')),
            'bot' => $kookBot,
            'error' => $kookError,
            'guildId' => (string) ($studioSettings['guildId'] ?? ''),
            'settingsUrl' => Common::url('options-plugin.php?config=MoePanel', (string) $options->adminUrl),
        ];

        $hour = (int) date('G');

        if ($hour < 5) {
            $greeting = '夜深了';
        } elseif ($hour < 11) {
            $greeting = '早上好';
        } elseif ($hour < 14) {
            $greeting = '中午好';
        } elseif ($hour < 19) {
            $greeting = '下午好';
        } else {
            $greeting = '晚上好';
        }

        $dashboard = self::view('dashboard', [
            'user' => $user,
            'stat' => $stat,
            'posts' => $posts,
            'comments' => $comments,
            'options' => $options,
            'displayName' => $displayName,
            'greeting' => $greeting,
            'weekday' => ['日', '一', '二', '三', '四', '五', '六'][(int) date('w')],
            'date' => date('Y.m.d'),
            'publishedPosts' => $isEditor ? (int) $stat->publishedPostsNum : (int) $stat->myPublishedPostsNum,
            'publishedComments' => $isEditor ? (int) $stat->publishedCommentsNum : (int) $stat->myPublishedCommentsNum,
            'waitingComments' => $isEditor ? (int) $stat->waitingCommentsNum : (int) $stat->myWaitingCommentsNum,
            'draftPosts' => $isEditor ? (int) $stat->draftPostsNum : (int) $stat->myDraftPostsNum,
            'categoriesNum' => (int) $stat->categoriesNum,
            'tagsNum' => (int) $stat->tagsNum,
            'isEditor' => $isEditor,
            'isAdmin' => $isAdmin,
            'canWrite' => $canWrite,
            'kook' => $kook,
            'checkVersion' => Common::url('/action/ajax?do=checkVersion', (string) $options->index),
        ]);

        return preg_replace_callback('~<main class="main">.*?</main>~is', static function () use ($dashboard): string {
            return $dashboard;
        }, $html, 1) ?? $html;
    }

    private static function kookSwitch(): ?array
    {
        try {
            if (!class_exists('TypechoPlugin\\MoePanel\\Kook\\Settings')) {
                return null;
            }

            $settings = \TypechoPlugin\MoePanel\Kook\Settings::load();

            if ('1' !== (string) ($settings['enable'] ?? '0')) {
                return null;
            }

            $hasOauth = '1' === (string) ($settings['oauthLogin'] ?? '0')
                && '' !== trim((string) ($settings['clientId'] ?? ''))
                && '' !== trim((string) ($settings['clientSecret'] ?? ''));
            $hasCode = '1' === (string) ($settings['codeLogin'] ?? '0')
                && '' !== trim((string) ($settings['botToken'] ?? ''));
            $owner = trim((string) ($settings['ownerKookId'] ?? ''));

            if ((!$hasOauth && !$hasCode) || !preg_match('/^\d{3,32}$/', $owner)) {
                return null;
            }

            return ['url' => (string) Options::alloc()->loginUrl];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function studioSettings(): array
    {
        try {
            if (class_exists('TypechoPlugin\\MoePanel\\Kook\\Settings')) {
                return \TypechoPlugin\MoePanel\Kook\Settings::load();
            }
        } catch (\Throwable $e) {
            // 设置表不可用时使用空置。
        }

        return ['logoUrl' => '', 'logoDarkUrl' => ''];
    }

    private static function syncCommentAuthors(string $html): string
    {
        if (!class_exists('TypechoPlugin\\MoePanel\\Kook\\Auth')) {
            return $html;
        }

        try {
            if (!preg_match_all('~id="comment-(\d+)"~i', $html, $matches)) {
                return $html;
            }

            $coids = array_values(array_unique(array_map('intval', $matches[1])));

            if ([] === $coids) {
                return $html;
            }

            $db = \Typecho\Db::get();
            $rows = $db->fetchAll(
                $db->select('coid', 'authorId')
                    ->from('table.comments')
                    ->where('coid IN (' . implode(',', array_map('intval', $coids)) . ')')
            );
            $owners = [];

            foreach ($rows as $row) {
                $owners[(int) $row['coid']] = (int) $row['authorId'];
            }

            $profiles = [];

            foreach (array_unique(array_values($owners)) as $uid) {
                if ($uid <= 0) {
                    continue;
                }

                $profile = \TypechoPlugin\MoePanel\Kook\Auth::profileForUid($uid);

                if (is_array($profile) && !empty($profile)) {
                    $profiles[$uid] = $profile;
                }
            }

            return preg_replace_callback(
                '~<tr\s+id="comment-(\d+)"[^>]*>.*?</tr>~is',
                static function (array $match) use ($owners, $profiles): string {
                    $coid = (int) $match[1];
                    $uid = $owners[$coid] ?? 0;

                    if ($uid <= 0 || !isset($profiles[$uid])) {
                        return $match[0];
                    }

                    $profile = $profiles[$uid];
                    $avatar = trim((string) ($profile['avatar'] ?? ''));
                    $name = trim((string) ($profile['nickname'] ?? ''));

                    if ('' === $name) {
                        $name = trim((string) ($profile['username'] ?? ''));
                    }

                    $identify = trim((string) ($profile['identify_num'] ?? ''));
                    $display = $name . ('' !== $identify ? '#' . $identify : '');
                    $row = $match[0];

                    if ('' !== $avatar) {
                        $image = '<img class="sp-comment-avatar" src="' . self::e($avatar)
                            . '" alt="" width="40" height="40" loading="lazy" referrerpolicy="no-referrer">';
                        $row = preg_replace(
                            '~<div class="comment-avatar">.*?</div>~s',
                            '<div class="comment-avatar">' . $image . '</div>',
                            $row,
                            1
                        ) ?? $row;
                    }

                    if ('' !== $display) {
                        $row = preg_replace(
                            '~<strong class="comment-author">.*?</strong>~s',
                            '<strong class="comment-author">' . self::e($display) . '</strong>',
                            $row,
                            1
                        ) ?? $row;
                    }

                    return $row;
                },
                $html
            ) ?? $html;
        } catch (\Throwable $ignored) {
            return $html;
        }
    }

    private static function injectPageOptions(string $html): string
    {
        try {
            $db = \Typecho\Db::get();
            $rows = $db->fetchAll(
                $db->select('cid', 'title', 'slug')
                    ->from('table.contents')
                    ->where('type = ?', 'page')
                    ->where('status = ?', 'publish')
                    ->order('cid', \Typecho\Db::SORT_ASC)
            );
            $pages = [];

            foreach ($rows as $row) {
                $pages[] = [
                    'cid' => (int) ($row['cid'] ?? 0),
                    'title' => (string) ($row['title'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                ];
            }

            $json = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
            $script = '<script type="application/json" id="sp-page-options">' . $json . '</script>';

            if (false !== stripos($html, '</body>')) {
                return preg_replace('~</body>~i', $script . '</body>', $html, 1) ?? $html;
            }

            return $html . $script;
        } catch (\Throwable $ignored) {
            return $html;
        }
    }

    private static function view(string $view, array $data): string
    {
        $e = static fn ($value): string => self::e((string) $value);
        $t = static fn (string $value): string => _t($value);
        $icon = static fn (string $name): string => self::icon($name);
        $url = static fn (string $path): string => self::adminUrl($path);

        extract($data, EXTR_SKIP);

        ob_start();
        include __DIR__ . '/templates/' . $view . '.php';

        return (string) ob_get_clean();
    }

    public static function icon(string $name): string
    {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'pen' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
            'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/><path d="M8 9h8M8 13h5"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.3 2.3-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-3.2v-.2a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-2.3-2.3.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H4v-3.2h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.3-2.3.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V4h3.2v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.3 2.3-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v3.2h-.2a1.7 1.7 0 0 0-1.6 1Z"/>',
            'arrow' => '<path d="M7 17 17 7"/><path d="M8 7h9v9"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon' => '<path d="M20.4 14.6A8.6 8.6 0 0 1 9.4 3.6 8.6 8.6 0 1 0 20.4 14.6Z"/>',
            'sparkle' => '<path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7Z"/><path d="M19 16.5 19.6 18l1.5.6-1.5.6-.6 1.5-.6-1.5-1.5-.6 1.5-.6Z"/>',
            'chevrons' => '<path d="m7 9 5-5 5 5"/><path d="m7 15 5 5 5-5"/>',
            'dot' => '<circle cx="12" cy="12" r="3"/>',
        ];
        $path = $paths[$name] ?? $paths['dot'];

        return '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $path . '</svg>';
    }

    private static function formAction(string $html): string
    {
        if (preg_match('~<form\b[^>]*\baction="([^"]*)"~i', $html, $match)) {
            return $match[1];
        }

        return '';
    }

    private static function inputTag(string $html, string $name): string
    {
        if (preg_match('~<input\b[^>]*\bname="' . preg_quote($name, '~') . '"[^>]*>~i', $html, $match)) {
            return $match[0];
        }

        return '';
    }

    private static function inputValue(string $html, string $name, string $default = ''): string
    {
        $tag = self::inputTag($html, $name);

        if ($tag !== '' && preg_match('~\bvalue="([^"]*)"~i', $tag, $match)) {
            return $match[1];
        }

        return $default;
    }

    private static function adminUrl(string $path): string
    {
        return Common::url($path, (string) Options::alloc()->adminUrl);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
