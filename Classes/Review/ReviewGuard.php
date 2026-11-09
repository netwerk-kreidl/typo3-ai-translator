<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Review;

/**
 * Unterscheidet Speichervorgänge der KI-Übersetzung von Speichervorgängen durch Redakteure:
 * Solange die Übersetzung läuft, gilt Speichern nicht als „geprüft“.
 */
final class ReviewGuard
{
    private static int $depth = 0;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isTranslating(): bool
    {
        return self::$depth > 0;
    }
}
