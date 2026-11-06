<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Test-Anbieter ohne KI und ohne Kosten – nur im Kontext „Development“ verfügbar.
 * Setzt „[EN] “ vor jeden Text, damit sich der gesamte Ablauf (Felder, Links, Slug, Protokoll)
 * ohne API-Key prüfen lässt.
 */
final class PseudoProvider implements TranslationProviderInterface
{
    public function getIdentifier(): string
    {
        return 'pseudo';
    }

    public function getLabel(): string
    {
        return 'Test-Übersetzung (ohne KI)';
    }

    public function isConfigured(): bool
    {
        return Environment::getContext()->isDevelopment();
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $prefix = '[' . strtoupper($request->languages->targetLanguage) . '] ';
        $translations = [];
        foreach ($request->segments as $id => $segment) {
            $translations[$id] = $segment->isHtml
                // vor den ersten sichtbaren Text, Tags bleiben unverändert
                ? (preg_replace('/^((?:\s*<[^>]+>)*\s*)/u', '$1' . $prefix, $segment->text, 1) ?? $segment->text)
                : $prefix . $segment->text;
        }
        return new TranslationResult($translations, $this->getIdentifier(), 'pseudo');
    }
}
