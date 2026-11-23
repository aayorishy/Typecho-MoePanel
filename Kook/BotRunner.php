<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * 从后台启动 / 停止 WebSocket Bot 进程。
 *
 * 这是便利入口；生产环境更推荐 systemd / supervisor / screen 保活。
 */
class BotRunner
{
    private static function dir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moepanel-kook-bot';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private static function script(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'kook-bot.php';
    }

    private static function php(): string
    {
        $candidates = [];
        $binary = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
        $isFpm = '' !== $binary && preg_match('/(?:php-?fpm|php-cgi|php-fpm\d*)/i', basename($binary));

        if ('' !== $binary && !$isFpm) {
            $candidates[] = $binary;
        }

        $bindir = defined('PHP_BINDIR') ? (string) PHP_BINDIR : '';
        $suffix = '\\' === DIRECTORY_SEPARATOR ? 'php.exe' : 'php';
        $versioned = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $openBasedir = trim((string) ini_get('open_basedir'));

        // open_basedir 下无法 stat PHP 安装目录，但 exec 仍可执行绝对路径。
        // PHP_BINDIR 在 php-fpm 里通常就是 /www/server/php/82/bin。
        if ('' !== $openBasedir) {
            if ('' !== $binary && !$isFpm) {
                return $binary;
            }

            if ('' !== $bindir) {
                return $bindir . DIRECTORY_SEPARATOR . $suffix;
            }

            return '/usr/bin/php';
        }

        foreach ([$bindir . DIRECTORY_SEPARATOR . $suffix, '/usr/bin/php', '/usr/local/bin/php', $bindir . DIRECTORY_SEPARATOR . $versioned] as $candidate) {
            if ('' !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        // 宝塔/aaPanel 的 PHP CLI 通常不在 open_basedir 内；
        // 探测路径时必须用 @ 抑制 open_basedir warning，否则会被接口错误处理器误判为启动失败。
        foreach (@glob('/www/server/php/*/bin/php') ?: [] as $candidate) {
            $candidates[] = $candidate;
        }

        foreach (array_unique($candidates) as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public static function status(): array
    {
        $dir = self::dir();
        $pidFile = $dir . '/bot.pid';
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
        $running = $pid > 0 && self::isRunning($pid);
        $uptime = '-';

        if ($running && is_file($pidFile)) {
            $seconds = self::processSeconds($pid);
            $startedAt = (int) @filemtime($pidFile);

            if ($seconds <= 0) {
                $seconds = max(0, time() - $startedAt);
            }

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            if ($days > 0) {
                $uptime = $days . 'd ' . $hours . 'h';
            } elseif ($hours > 0) {
                $uptime = $hours . 'h ' . $minutes . 'm';
            } elseif ($minutes > 0) {
                $uptime = $minutes . 'm';
            } else {
                $uptime = $seconds . 's';
            }
        }

        return [
            'running' => $running,
            'pid' => $running ? $pid : 0,
            'uptime' => $uptime,
            'log' => self::allLogs(),
            'logs' => self::logSources(),
            'server' => self::serverInfo(),
            'bot' => self::botInfo(),
        ];
    }

    public static function start(): array
    {
        $status = self::status();

        if ($status['running']) {
            return $status + ['ok' => true, 'message' => '机器人已在运行'];
        }

        try {
            $settings = Settings::load();
        } catch (\Throwable $exception) {
            $settings = [];
        }

        if ('' === trim((string) ($settings['botToken'] ?? ''))) {
            return ['ok' => false, 'error' => '请先在应用设置中填写机器人 Token'];
        }

        if (!function_exists('exec') && !function_exists('proc_open') && !function_exists('popen')) {
            return ['ok' => false, 'error' => 'PHP 禁用了 exec / proc_open / popen，无法从后台启动，请手动运行 kook-bot.php'];
        }

        $dir = self::dir();
        $log = $dir . '/bot.log';
        $script = self::script();
        $php = self::php();
        @file_put_contents($log, '');

        if ('' === $php) {
            return ['ok' => false, 'error' => '找不到 PHP CLI（当前可能是 php-fpm）。请用 php-cli 手动运行 kook-bot.php'];
        }

        if (!is_file($script)) {
            return ['ok' => false, 'error' => '找不到 kook-bot.php'];
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' >> ' . escapeshellarg($log) . ' 2>&1';
            $output = [];
            $code = 0;
            @exec($command, $output, $code);

            return [
                'ok' => 0 === $code,
                'running' => 0 === $code,
                'pid' => 0,
                'log' => self::allLogs(),
                'message' => 0 === $code ? '已发送启动命令' : '启动命令执行失败',
            ];
        }

        @unlink($dir . '/bot.pid');
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $output = [];
        $code = 0;
        $launched = false;

        if (function_exists('exec')) {
            @exec($command, $output, $code);
            $launched = 0 === $code;
        } elseif (function_exists('proc_open')) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ];
            $process = @proc_open(
                'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 &',
                $descriptors,
                $pipes,
                $dir
            );
            $launched = is_resource($process);

            if ($launched) {
                @proc_close($process);
            }
        } else {
            $handle = @popen('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 &', 'r');
            $launched = is_resource($handle);

            if ($launched) {
                @pclose($handle);
            }
        }

        if (!$launched) {
            return [
                'ok' => false,
                'running' => false,
                'pid' => 0,
                'log' => self::allLogs(),
                'error' => '无法创建后台进程，请检查 PHP 的 exec / proc_open / popen 设置',
            ];
        }

        // 等待脚本写入 pid，避免“命令成功但进程立刻退出”被误报为运行中。
        for ($i = 0; $i < 8; $i++) {
            usleep(180000);
            $pid = is_file($dir . '/bot.pid') ? (int) trim((string) file_get_contents($dir . '/bot.pid')) : 0;

            if ($pid > 0 && self::isRunning($pid)) {
                return array_merge(self::status(), [
                    'ok' => true,
                    'message' => '机器人已启动',
                ]);
            }
        }

        $pid = is_file($dir . '/bot.pid') ? (int) trim((string) file_get_contents($dir . '/bot.pid')) : 0;

        return [
            'ok' => false,
            'running' => false,
            'pid' => $pid,
            'log' => self::allLogs(),
            'error' => '启动命令已执行，但 Bot 没有保持运行。请查看日志；常见原因是 PHP CLI 路径错误或 Token 无效。',
        ];
    }

    public static function stop(): array
    {
        $dir = self::dir();
        $pidFile = $dir . '/bot.pid';
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if ($pid <= 0) {
            return ['ok' => true, 'running' => false, 'message' => '没有记录到运行中的 Bot'];
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            @exec('taskkill /PID ' . $pid . ' /F');
        } else {
            @exec('kill ' . $pid);
        }

        @unlink($pidFile);

        return ['ok' => true, 'running' => false, 'message' => '已请求停止 Bot'];
    }

    private static function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            $output = [];
            @exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output);
            return (bool) array_filter($output, static function ($line) use ($pid): bool {
                return false !== strpos((string) $line, (string) $pid);
            });
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }

    private static function processSeconds(int $pid): int
    {
        if ($pid <= 0 || '\\' === DIRECTORY_SEPARATOR || !function_exists('exec')) {
            return 0;
        }

        $output = [];
        @exec('ps -o etimes= -p ' . $pid, $output);
        $seconds = (int) trim((string) ($output[0] ?? ''));

        return $seconds > 0 ? $seconds : 0;
    }

    private static function botInfo(): ?array
    {
        try {
            $settings = Settings::load();
            $token = trim((string) ($settings['botToken'] ?? ''));

            if ('' === $token) {
                return null;
            }

            $client = new KookClient($token);
            $client->setTimeout(4);
            $me = $client->me();

            return [
                'id' => (string) ($me['id'] ?? ''),
                'username' => (string) ($me['username'] ?? 'KOOK Bot'),
                'avatar' => (string) ($me['avatar'] ?? ''),
                'online' => !empty($me['online']),
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private static function serverInfo(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $free = @disk_free_space('/');
        $total = @disk_total_space('/');
        $uptime = '';

        // open_basedir 常见于宝塔环境，/proc 通常不在允许路径内。
        if ('' === (string) ini_get('open_basedir') && @is_readable('/proc/uptime')) {
            $rawUptime = @file_get_contents('/proc/uptime');

            if (is_string($rawUptime) && '' !== trim($rawUptime)) {
                $seconds = (int) explode(' ', trim($rawUptime))[0];
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);
                $uptime = $days . 'd ' . $hours . 'h';
            }
        }

        return [
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php' => PHP_VERSION,
            'load' => is_array($load) ? number_format((float) $load[0], 2) : '-',
            'memory' => number_format(memory_get_usage(true) / 1048576, 1) . ' MB',
            'disk' => is_numeric($free) && is_numeric($total) && $total > 0
                ? number_format($free / 1073741824, 1) . ' / ' . number_format($total / 1073741824, 1) . ' GB'
                : '-',
            'uptime' => $uptime ?: '-',
        ];
    }

    private static function logSources(): array
    {
        $dir = self::dir();
        $tmp = rtrim(sys_get_temp_dir(), '/\\');

        return [
            'KOOK BOT' => $dir . '/bot.log',
            'AI CHAT' => $tmp . '/moepanel-ai-chat.log',
            'EVENTS' => $tmp . '/moepanel-events.log',
            'WELCOME' => $tmp . '/moepanel-welcome.log',
            'ROLE CLAIM' => $tmp . '/moepanel-role-claim.log',
            'POST PUSH' => $dir . '/post-push.log',
        ];
    }

    private static function allLogs(): string
    {
        $sections = [];

        foreach (self::logSources() as $name => $file) {
            $content = self::tail($file, 160);
            $sections[] = '===== ' . $name . ' =====' . "\n" . ($content !== '' ? $content : '（暂无日志）');
        }

        return implode("\n\n", $sections);
    }

    private static function tail(string $file, int $limit = 12): string
    {
        if (!is_file($file)) {
            return '';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return implode("\n", array_slice(is_array($lines) ? $lines : [], -max(1, $limit)));
    }
}
