<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logger fichier minimal (pas de dependance externe).
 * Un fichier par canal, une ligne JSON par entree (facile a parser plus tard).
 */
final class Logger
{
    private static function path(string $channel): string
    {
        return __DIR__ . '/../../logs/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log';
    }

    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents(self::path($channel), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'INFO', $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'WARNING', $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'ERROR', $message, $context);
    }
}
