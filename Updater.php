<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel;

/**
 * GitHub Releases update checker / updater.
 */
final class Updater
{
    public const REPO = 'aayorishy/Typecho-MoePanel';

    public static function check(bool $force = false): array
    {
        $cacheFile = self::cacheFile();

        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);

            if (is_array($cached) && (int) ($cached['checked_at'] ?? 0) > time() - 21600) {
                return $cached;
            }
        }

        $result = [
            'ok' => false,
            'current' => Plugin::version(),
            'latest' => '',
            'has_update' => false,
            'url' => 'https://github.com/' . self::REPO . '/releases',
            'download' => '',
            'digest' => '',
            'notes' => '',
            'checked_at' => time(),
            'error' => '',
        ];

        $ch = curl_init('https://api.github.com/repos/' . self::REPO . '/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: MoePanel-Updater/' . Plugin::version(),
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (false === $body || 200 !== $status) {
            $result['error'] = '' !== $error ? $error : ('GitHub API HTTP ' . $status);
            @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result;
        }

        $release = json_decode((string) $body, true);

        if (!is_array($release)) {
            $result['error'] = 'GitHub 返回数据无效';
            return $result;
        }

        $tag = (string) ($release['tag_name'] ?? '');
        $latest = ltrim($tag, 'vV');
        $result['latest'] = $latest;
        $result['notes'] = mb_substr((string) ($release['body'] ?? ''), 0, 4000, 'UTF-8');
        $result['has_update'] = '' !== $latest && version_compare($latest, Plugin::version(), '>');

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && preg_match('/\.zip$/i', (string) ($asset['name'] ?? ''))) {
                $result['download'] = (string) ($asset['browser_download_url'] ?? '');
                $result['digest'] = (string) ($asset['digest'] ?? '');
                break;
            }
        }

        if ('' === $result['download']) {
            $result['download'] = (string) ($release['zipball_url'] ?? '');
        }

        $result['ok'] = true;
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public static function install(string $downloadUrl = '', string $expectedDigest = ''): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('服务器未安装 ZipArchive，无法自动更新');
        }

        if ('' === $downloadUrl) {
            $release = self::check(true);
            $downloadUrl = (string) ($release['download'] ?? '');
        }

        if ('' === $downloadUrl || !preg_match('#^https://#i', $downloadUrl)) {
            throw new \RuntimeException('没有可用的更新包');
        }

        $work = sys_get_temp_dir() . '/moepanel-update-' . bin2hex(random_bytes(4));
        @mkdir($work, 0700, true);
        $zipFile = $work . '/release.zip';
        $fp = fopen($zipFile, 'wb');

        if (false === $fp) {
            throw new \RuntimeException('无法创建临时更新文件');
        }

        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['User-Agent: MoePanel-Updater/' . Plugin::version()],
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if (false === $ok || 200 !== $status) {
            throw new \RuntimeException('更新包下载失败（HTTP ' . $status . '）');
        }

        if ('' !== $expectedDigest && preg_match('/^sha256:([a-f0-9]{64})$/i', $expectedDigest, $match)) {
            $actual = hash_file('sha256', $zipFile);

            if (!hash_equals(strtolower($match[1]), strtolower((string) $actual))) {
                throw new \RuntimeException('更新包 SHA-256 校验失败，已拒绝安装');
            }
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($zipFile)) {
            throw new \RuntimeException('更新包无法解压');
        }

        $zip->extractTo($work . '/extract');
        $zip->close();
        $source = self::findPluginRoot($work . '/extract');

        if ('' === $source) {
            throw new \RuntimeException('更新包中找不到 Plugin.php');
        }

        self::copyTree($source, dirname(__DIR__));
        self::clearCache();

        return ['ok' => true, 'version' => self::check(true)];
    }

    private static function findPluginRoot(string $dir): string
    {
        if (is_file($dir . '/Plugin.php')) {
            return $dir;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) continue;
            $path = $dir . '/' . $item;

            if (is_dir($path) && is_file($path . '/Plugin.php')) {
                return $path;
            }
        }

        return '';
    }

    private static function copyTree(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($destination)) @mkdir($destination, 0755, true);
            } else {
                @copy($item->getPathname(), $destination);
            }
        }
    }

    private static function cacheFile(): string
    {
        return sys_get_temp_dir() . '/moepanel-update.json';
    }

    private static function clearCache(): void
    {
        @unlink(self::cacheFile());
    }
}
