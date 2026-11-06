<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final readonly class LanguagePair
{
    public function __construct(
        // z. B. „de“, „en“
        public string $sourceLanguage,
        public string $targetLanguage,
        // Region der Zielsprache, z. B. „US“, „GB“, „BR“ (leer = unbekannt)
        public string $targetRegion = '',
        // Lesbare Namen für Prompts, z. B. „Deutsch“, „English (US)“
        public string $sourceName = '',
        public string $targetName = '',
    ) {}

    public static function fromSiteLanguages(SiteLanguage $source, SiteLanguage $target): self
    {
        return new self(
            strtolower($source->getLocale()->getLanguageCode()),
            strtolower($target->getLocale()->getLanguageCode()),
            strtoupper((string)$target->getLocale()->getCountryCode()),
            $source->getTitle() . ' (' . $source->getLocale()->getName() . ')',
            $target->getTitle() . ' (' . $target->getLocale()->getName() . ')',
        );
    }
}
