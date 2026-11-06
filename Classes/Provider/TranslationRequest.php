<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

/**
 * Eine Übersetzungsaufgabe: mehrere Textsegmente einer Seite, gemeinsam übersetzt.
 */
final readonly class TranslationRequest
{
    /**
     * @param array<string, Segment> $segments Schlüssel = eindeutige Segment-ID
     */
    public function __construct(
        public array $segments,
        public LanguagePair $languages,
        public TranslationStyle $style,
        // Kurze Beschreibung des Zusammenhangs, z. B. „Website eines Hotels in Wien, Seite: Zimmer & Suiten“
        public string $context = '',
    ) {}
}
