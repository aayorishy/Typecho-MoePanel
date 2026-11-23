<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

use Typecho\Db;

/**
 * 新文章发布后推送一张 KOOK 卡片到指定频道。
 *
 * 同一 cid 只推送一次，避免编辑旧文章时重复刷屏。
 */
class PostNotifier
{
    public const CHANNEL_ID = '6161751426312745';
    private const TABLE = 'kookauth_post_pushes';

    public static function ensureTable(): void
    {
        $db = Db::get();
        $table = $db->getPrefix() . self::TABLE;
        $db->query(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . '`cid` INT NOT NULL PRIMARY KEY,'
            . '`pushed_at` INT NOT NULL'
            . ')',
            Db::WRITE
        );
    }

    private static function log(string $message): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moepanel-kook-bot';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($dir . '/post-push.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }

    public static function finishPublish($contents, $post): void
    {
        try {
            $cid = (int) ($post->cid ?? $contents['cid'] ?? 0);
            self::log('hook cid=' . $cid . ' status=' . (string) ($post->status ?? $contents['status'] ?? ''));

            if ($cid <= 0) {
                return;
            }

            $status = (string) ($post->status ?? $contents['status'] ?? 'publish');

            if ('' !== $status && 'publish' !== $status) {
                return;
            }

            $db = Db::get();
            self::ensureTable();
            $exists = $db->fetchRow(
                $db->select('cid')->from('table.' . self::TABLE)->where('cid = ?', $cid)->limit(1)
            );

            if ($exists) {
                return;
            }

            $settings = Settings::load();
            $token = trim((string) ($settings['botToken'] ?? ''));
            self::log('token=' . ('' !== $token ? 'yes' : 'no') . ' channel=' . (string) ($settings['postChannelId'] ?? ''));

            if ('' === $token) {
                return;
            }

            $title = trim((string) ($post->title ?? $contents['title'] ?? '新文章'));
            $title = '' !== $title ? $title : '新文章';
            $permalink = (string) ($post->permalink ?? '');
            self::log('permalink=' . $permalink);

            if ('' === $permalink) {
                return;
            }

            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($post->text ?? ''))) ?? '');
            $summary = mb_substr($text, 0, 180, 'UTF-8');
            $summary = '' !== $summary ? $summary : '新文章已发布，点击阅读全文。';
            $author = (string) ($post->author->screenName ?? '');
            $created = (int) ($post->created ?? time());
            $context = [];

            if ('' !== $author) {
                $context[] = '作者 · ' . $author;
            }

            $context[] = '发布于 · ' . date('Y-m-d H:i', $created);

            $site = (string) \Widget\Options::alloc()->title;
            $channelId = trim((string) ($settings['postChannelId'] ?? ''));
            $channelId = '' !== $channelId ? $channelId : self::CHANNEL_ID;
            $titleTemplate = trim((string) ($settings['postCardTitle'] ?? ''));
            $bodyTemplate = trim((string) ($settings['postCardBody'] ?? ''));
            $titleTemplate = '' !== $titleTemplate ? $titleTemplate : '新文章 · {title}';
            $bodyTemplate = '' !== $bodyTemplate ? $bodyTemplate : "{summary}\n\n[阅读全文]({url})";
            $replace = static function (string $template) use ($title, $summary, $permalink, $site, $author, $created): string {
                return strtr($template, [
                    '{title}' => $title,
                    '{summary}' => $summary,
                    '{url}' => $permalink,
                    '{site}' => $site,
                    '{author}' => $author,
                    '{time}' => date('Y-m-d H:i', $created),
                ]);
            };

            $client = new KookClient($token);
            $client->setTimeout(6);
            $client->sendChannelCard(
                $channelId,
                (string) ($settings['cardColor'] ?? KookClient::DEFAULT_CARD_COLOR),
                mb_substr($replace($titleTemplate), 0, 80, 'UTF-8'),
                $replace($bodyTemplate),
                $context
            );

            $db->query($db->insert('table.' . self::TABLE)->rows([
                'cid' => $cid,
                'pushed_at' => time(),
            ]));
        } catch (\Throwable $error) {
            self::log('error=' . $error->getMessage());
        }
    }
}
