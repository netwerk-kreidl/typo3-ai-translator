<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

final readonly class TranslationResult
{
    /**
     * @param array<string, string> $translations Segment-ID => Übersetzung
     */
    public function __construct(
        public array $translations,
        public string $provider,
        public string $model = '',
        public int $characters = 0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
