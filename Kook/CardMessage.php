<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

/**
 * KOOK CardMessage factory.
 *
 * Every bot-facing message uses the same card foundation and #FFCDCC accent.
 */
final class CardMessage
{
    public const COLOR = '#FFCDCC';

    public static function card(array $modules, string $color = self::COLOR): array
    {
        return [
            'type' => 'card',
            'color' => self::normalizeColor($color),
            'size' => 'lg',
            'modules' => array_values($modules),
        ];
    }

    public static function encode(array $cards): string
    {
        return json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Text-only welcome card.
     */
    public static function welcomeMessage(string $greeting, string $footer = '', string $title = 'WELCOME'): array
    {
        $modules = [[
            'type' => 'header',
            'text' => ['type' => 'plain-text', 'content' => self::limit($title, 90)],
        ]];

        if ('' !== trim($greeting)) {
            $modules[] = [
                'type' => 'section',
                'text' => ['type' => 'kmarkdown', 'content' => self::limit($greeting, 1800)],
            ];
        }

        if ('' !== trim($footer)) {
            $modules[] = [
                'type' => 'context',
                'elements' => [['type' => 'plain-text', 'content' => self::limit($footer, 180)]],
            ];
        }

        return [self::card($modules)];
    }

    public static function notice(string $title, string $body, string $footer = ''): array
    {
        $modules = [[
            'type' => 'header',
            'text' => ['type' => 'plain-text', 'content' => self::limit($title, 90)],
        ], [
            'type' => 'section',
            'text' => ['type' => 'kmarkdown', 'content' => self::limit($body, 1800)],
        ]];

        if ('' !== trim($footer)) {
            $modules[] = [
                'type' => 'context',
                'elements' => [['type' => 'plain-text', 'content' => self::limit($footer, 180)]],
            ];
        }

        return [self::card($modules)];
    }

    public static function aiReply(string $text, string $title = '角色扮演'): array
    {
        return [self::card([
            [
                'type' => 'header',
                'text' => ['type' => 'plain-text', 'content' => self::limit($title, 40)],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'kmarkdown', 'content' => self::limit($text, 1800)],
            ],
            [
                'type' => 'context',
                'elements' => [
                    ['type' => 'plain-text', 'content' => 'MoePanel · 由 DeepSeek 生成'],
                ],
            ],
        ])];
    }

    public static function rolePanel(string $title, string $description, string $buttonText): array
    {
        return [self::card([
            [
                'type' => 'header',
                'text' => ['type' => 'plain-text', 'content' => self::limit($title, 90)],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'kmarkdown', 'content' => self::limit($description, 1200)],
            ],
            [
                'type' => 'action-group',
                'elements' => [[
                    'type' => 'button',
                    'theme' => 'primary',
                    'value' => 'claim_roles',
                    'click' => 'return-val',
                    'text' => ['type' => 'plain-text', 'content' => self::limit($buttonText, 30)],
                ]],
            ],
        ])];
    }

    private static function limit(string $text, int $length): string
    {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8') . '…'
            : $text;
    }

    private static function normalizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : self::COLOR;
    }
}
