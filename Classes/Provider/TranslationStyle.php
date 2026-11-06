<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

final readonly class TranslationStyle
{
    /**
     * @param 'formal'|'informal'|'default' $formality
     * @param list<string> $doNotTranslate
     * @param array<string, string> $glossary
     */
    public function __construct(
        public string $formality = 'default',
        public string $styleHint = '',
        public array $doNotTranslate = [],
        public array $glossary = [],
    ) {}
}
