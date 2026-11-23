<?php

declare(strict_types=1);

/**
 * KOOK WebSocket Bot 常驻进程（插件根目录常规入口，不使用 bin）。
 *
 * 用法：
 *   php /path/to/usr/plugins/MoePanel/kook-bot.php
 *
 * 建议用 systemd / supervisor / screen / tmux 保活。
 */

if ('cli' !== PHP_SAPI) {
    http_response_code(403);
    exit('CLI only');
}

$runtimeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moepanel-kook-bot';

if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0700, true);
}

$pidFile = $runtimeDir . '/bot.pid';

if (false !== @file_put_contents($pidFile, (string) getmypid())) {
    register_shutdown_function(static function () use ($pidFile): void {
        if (is_file($pidFile) && (int) trim((string) @file_get_contents($pidFile)) === getmypid()) {
            @unlink($pidFile);
        }
    });
}

$root = dirname(__DIR__, 3);

if (!is_file($root . '/config.inc.php')) {
    fwrite(STDERR, "找不到 Typecho config.inc.php\n");
    exit(1);
}

require_once $root . '/config.inc.php';
require_once __DIR__ . '/Kook/Settings.php';
require_once __DIR__ . '/Kook/KookClient.php';
require_once __DIR__ . '/Kook/WebSocketException.php';
require_once __DIR__ . '/Kook/WebSocketConnection.php';
require_once __DIR__ . '/Kook/BotDaemon.php';
require_once __DIR__ . '/Kook/CardMessage.php';
require_once __DIR__ . '/Kook/RoleClaim.php';
require_once __DIR__ . '/Kook/Welcome.php';
foreach (['DeepSeekClient', 'AiConversationStore', 'AiChatService'] as $aiClass) {
    $aiFile = __DIR__ . '/Kook/' . $aiClass . '.php';

    if (is_file($aiFile)) {
        require_once $aiFile;
    }
}

use TypechoPlugin\MoePanel\Kook\BotDaemon;
use TypechoPlugin\MoePanel\Kook\Settings;

try {
    $settings = Settings::load();
} catch (Throwable $error) {
    fwrite(STDERR, '读取设置失败：' . $error->getMessage() . "\n");
    exit(1);
}

$settings['siteTitle'] = (string) \Widget\Options::alloc()->title;
$token = trim((string) ($settings['botToken'] ?? ''));

if ('' === $token) {
    fwrite(STDERR, "机器人 Token 未配置，请先在 MoePanel 设置中填写。\n");
    exit(1);
}

$lockPath = sys_get_temp_dir() . '/moepanel-kook-bot.lock';
$lockHandle = @fopen($lockPath, 'c');

if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "已经有一个 MoePanel Bot 在运行，请先停止旧进程。\n");
    exit(1);
}

$runtimeDir = sys_get_temp_dir() . '/moepanel-kook-bot';

if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0755, true);
}

$pidFile = $runtimeDir . '/bot.pid';
$ownPid = (string) getmypid();
@file_put_contents($pidFile, $ownPid);
@chmod($pidFile, 0666);
register_shutdown_function(static function () use ($pidFile, $ownPid): void {
    if (is_file($pidFile) && trim((string) @file_get_contents($pidFile)) === $ownPid) {
        @unlink($pidFile);
    }
});

fwrite(STDOUT, "MoePanel KOOK WebSocket Bot\n");
@file_put_contents(
    sys_get_temp_dir() . '/moepanel-ai-chat.log',
    '[' . date('Y-m-d H:i:s') . '] Bot 启动 MoePanel ' . Settings::VERSION . PHP_EOL,
    FILE_APPEND
);
@chmod(sys_get_temp_dir() . '/moepanel-ai-chat.log', 0666);
fwrite(STDOUT, "Bot ID: " . (string) ($settings['ownerKookId'] ?? '-') . "\n");
fwrite(STDOUT, "正在获取 WebSocket 网关…\n");

try {
    (new BotDaemon($token, $settings))->run();
} catch (Throwable $error) {
    fwrite(STDERR, '启动失败：' . $error->getMessage() . "\n");
    exit(1);
}
