<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

final class Presence
{
    public static function start(array $settings, string $type, string $game = '', string $song = '', string $singer = '', string $software = 'cloudmusic'): array
    {
        $token = trim((string) ($settings['botToken'] ?? ''));

        if ('' === $token) {
            throw new KookException('请先填写机器人 Token');
        }

        $client = new KookClient($token);
        $client->setTimeout(8);

        if ('game' === $type) {
            return $client->startGameActivity($game);
        }

        if ('music' === $type) {
            if ('' === trim($song) || '' === trim($singer)) {
                throw new KookException('请填写歌曲名与歌手');
            }

            return $client->startMusicActivity($song, $singer, $software);
        }

        throw new KookException('未知的在玩状态类型');
    }

    public static function stop(array $settings, string $type): array
    {
        $token = trim((string) ($settings['botToken'] ?? ''));

        if ('' === $token) {
            throw new KookException('请先填写机器人 Token');
        }

        $client = new KookClient($token);
        $client->setTimeout(8);

        return $client->stopActivity('music' === $type ? 2 : 1);
    }
}
