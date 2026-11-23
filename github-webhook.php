<?php

declare(strict_types=1);

/**
 * GitHub -> KOOK 推送 Webhook。
 *
 * GitHub 仓库 Settings -> Webhooks 填：
 *   Payload URL: https://你的站点/usr/plugins/MoePanel/github-webhook.php
 *   Content type: application/json
 *   Secret: 与 MoePanel 设置中的 GitHub Webhook Secret 一致
 *   Events: Just the push event
 */

$root = dirname(__DIR__, 3);

if (!is_file($root . '/config.inc.php')) {
    http_response_code(500);
    exit('Typecho config not found');
}

require_once $root . '/config.inc.php';
require_once __DIR__ . '/Kook/Settings.php';
require_once __DIR__ . '/Kook/KookClient.php';

use TypechoPlugin\MoePanel\Kook\KookClient;
use TypechoPlugin\MoePanel\Kook\Settings;

header('Content-Type: application/json; charset=utf-8');

$event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
$raw = (string) file_get_contents('php://input');

if ('ping' === $event) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'pong']);
    exit;
}

if ('push' !== $event) {
    http_response_code(202);
    echo json_encode(['ok' => true, 'message' => 'ignored event']);
    exit;
}

try {
    $settings = Settings::load();
    $secret = trim((string) ($settings['githubSecret'] ?? ''));
    $token = trim((string) ($settings['botToken'] ?? ''));
    $channelId = trim((string) ($settings['githubChannelId'] ?? ''));
    $channelId = '' !== $channelId ? $channelId : trim((string) ($settings['uploadChannelId'] ?? ''));

    if ('' === $secret || '' === $token) {
        throw new RuntimeException('Webhook Secret 或机器人 Token 未配置');
    }

    $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);

    if ('' === $signature || !hash_equals($expected, $signature)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid signature']);
        exit;
    }

    if ('' === $channelId) {
        throw new RuntimeException('GitHub 通知频道未配置');
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        throw new RuntimeException('无效的 GitHub payload');
    }

    $repo = (string) ($payload['repository']['full_name'] ?? $payload['repository']['name'] ?? 'GitHub');
    $repoUrl = (string) ($payload['repository']['html_url'] ?? '#');
    $description = trim((string) ($payload['repository']['description'] ?? ''));
    $ref = (string) ($payload['ref'] ?? '');
    $branch = preg_replace('~^refs/heads/~', '', $ref) ?: 'main';
    $pusher = (string) ($payload['pusher']['name'] ?? $payload['sender']['login'] ?? 'unknown');
    $pusherLogin = (string) ($payload['sender']['login'] ?? $pusher);
    $profileUrl = 'https://github.com/' . rawurlencode($pusherLogin);
    $avatar = (string) ($payload['sender']['avatar_url'] ?? 'https://img.kookapp.cn/assets/2022-09/DknXSpwrlQ0e80e8.gif');
    $before = substr((string) ($payload['before'] ?? ''), 0, 7);
    $after = substr((string) ($payload['after'] ?? ''), 0, 7);
    $compare = (string) ($payload['compare'] ?? '');
    $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
    $limit = static function (string $value, int $length): string {
        return mb_strlen($value, 'UTF-8') > $length
            ? mb_substr($value, 0, $length, 'UTF-8') . '…'
            : $value;
    };
    $modules = [
        [
            'type' => 'section',
            'text' => ['type' => 'kmarkdown', 'content' => '**[' . $repo . '](' . $repoUrl . ')** 有新的推送'],
        ],
        [
            'type' => 'section',
            'text' => [
                'type' => 'kmarkdown',
                'content' => '**推送人员：[' . $pusher . '](' . $profileUrl . ')**' . "\n" . '**分支信息：' . $branch . '**',
            ],
            'accessory' => ['type' => 'image', 'src' => $avatar, 'alt' => $pusher, 'size' => 'sm'],
        ],
    ];

    if ('' !== $before && '' !== $after) {
        if ('' === $compare) {
            $compare = $repoUrl . '/compare/' . $before . '...' . $after;
        }

        $modules[] = [
            'type' => 'section',
            'text' => [
                'type' => 'kmarkdown',
                'content' => '[' . $before . '](' . $compare . ') -> [' . $after . '](' . $compare . ')',
            ],
        ];
    }

    foreach (array_slice($commits, 0, 4) as $commit) {
        if (!is_array($commit)) {
            continue;
        }

        $message = trim((string) ($commit['message'] ?? ''));
        $parts = explode("\n", $message, 2);
        $title = trim((string) ($parts[0] ?? $message));
        $body = trim((string) ($parts[1] ?? ''));
        $url = (string) ($commit['url'] ?? '');
        $hash = substr((string) ($commit['id'] ?? ''), 0, 7);
        $author = (string) ($commit['author']['username'] ?? $commit['author']['name'] ?? $pusher);
        $link = '' !== $url ? $url : rtrim($repoUrl, '/') . '/commit/' . $hash;
        $text = '[' . $limit($title, 140) . '](' . $link . ')';

        if ('' !== $body) {
            $text .= "\n\n" . $limit($body, 450);
        }

        $modules[] = [
            'type' => 'section',
            'text' => ['type' => 'kmarkdown', 'content' => $text],
        ];

        $context = [
            ['type' => 'image', 'src' => $avatar, 'alt' => $author, 'size' => 'sm'],
            ['type' => 'kmarkdown', 'content' => '**' . $limit($author, 32) . '**'],
        ];

        if ('' !== $hash) {
            $context[] = ['type' => 'kmarkdown', 'content' => '  `' . $hash . '`'];
        }

        $modules[] = ['type' => 'context', 'elements' => $context];
    }

    $modules[] = ['type' => 'divider'];
    $note = trim((string) ($settings['githubCardNote'] ?? ''));
    $modules[] = [
        'type' => 'context',
        'elements' => [
            '' !== $note
                ? ['type' => 'kmarkdown', 'content' => $limit($note, 600)]
                : ['type' => 'plain-text', 'content' => '' !== $description ? $limit($description, 180) : 'GitHub · Push'],
        ],
    ];

    $card = [
        'type' => 'card',
        'color' => (string) ($settings['cardColor'] ?? KookClient::DEFAULT_CARD_COLOR),
        'size' => 'lg',
        'modules' => $modules,
    ];

    $client = new KookClient($token);
    $client->setTimeout(8);
    $client->sendChannelCardPayload($channelId, $card);

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
