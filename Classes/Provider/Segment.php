<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

/**
 * Ein zu übersetzender Text. HTML-Segmente behalten Tags und Attribute (außer title/alt) unverändert.
 */
final readonly class Segment
{
    public function __construct(
        public string $text,
        public bool $isHtml = false,
        // Hinweis für ChatGPT/Claude, z. B. „Überschrift (Text & Bild)“
        public string $hint = '',
        // Maximale Länge laut TCA (0 = unbegrenzt)
        public int $maxLength = 0,
    ) {}
}
