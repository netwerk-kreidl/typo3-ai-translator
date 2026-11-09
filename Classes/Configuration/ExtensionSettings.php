<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Configuration;

use Netwerk\AiTranslator\Provider\TranslationStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Einstellungen der Extension. API-Keys können (und sollten) per Umgebungsvariable gesetzt werden –
 * diese haben Vorrang vor der Extension-Konfiguration, damit keine Schlüssel in settings.php landen.
 *
 * Stil, Glossar und „Nicht übersetzen“ lassen sich zusätzlich pro Website über das Site Set
 * „netwerk/ai-translator“ festlegen (siehe styleFor()).
 */
final class ExtensionSettings
{
    private const ENV = [
        'deeplApiKey' => 'AI_TRANSLATOR_DEEPL_API_KEY',
        'openaiApiKey' => 'AI_TRANSLATOR_OPENAI_API_KEY',
        'anthropicApiKey' => 'AI_TRANSLATOR_ANTHROPIC_API_KEY',
        'defaultProvider' => 'AI_TRANSLATOR_DEFAULT_PROVIDER',
    ];

    /** @var array<string, mixed>|null */
    private ?array $configuration = null;

    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration) {}

    public function get(string $key, string $default = ''): string
    {
        if (isset(self::ENV[$key])) {
            $env = getenv(self::ENV[$key]);
            if (is_string($env) && trim($env) !== '') {
                return trim($env);
            }
        }
        $this->configuration ??= $this->load();
        $value = $this->configuration[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function isFromEnvironment(string $key): bool
    {
        return isset(self::ENV[$key]) && is_string(getenv(self::ENV[$key])) && trim((string)getenv(self::ENV[$key])) !== '';
    }

    /**
     * @return list<string>
     */
    public function getList(string $key, string $separator = ','): array
    {
        return array_values(array_filter(array_map(trim(...), explode($separator, $this->get($key))), static fn(string $v): bool => $v !== ''));
    }

    /**
     * Globales Glossar (Extension-Konfiguration) für eine Zielsprache.
     *
     * @return array<string, string> Begriff => Übersetzung
     */
    public function getGlossary(string $targetLanguage = ''): array
    {
        return self::parseGlossary($this->get('glossary'), $targetLanguage);
    }

    /**
     * Übersetzungsstil für eine Website und Zielsprache: Website-Einstellungen vor globalen Einstellungen.
     */
    public function styleFor(?Site $site, string $targetLanguage): TranslationStyle
    {
        $siteSettings = $site?->getSettings();
        $formality = (string)($siteSettings?->get('aiTranslator.formality', '') ?? '') ?: $this->get('formality');
        $styleHint = trim((string)($siteSettings?->get('aiTranslator.styleHint', '') ?? '')) ?: $this->get('styleHint');
        $siteTerms = $siteSettings?->get('aiTranslator.doNotTranslate', []) ?? [];
        $doNotTranslate = array_values(array_unique(array_filter(array_map(
            static fn($term): string => trim((string)$term),
            array_merge($this->getList('doNotTranslate'), is_array($siteTerms) ? $siteTerms : explode(',', (string)$siteTerms))
        ), static fn(string $term): bool => $term !== '')));
        $glossary = array_merge(
            $this->getGlossary($targetLanguage),
            self::parseGlossary((string)($siteSettings?->get('aiTranslator.glossary', '') ?? ''), $targetLanguage)
        );
        return new TranslationStyle(
            in_array($formality, ['formal', 'informal'], true) ? $formality : 'default',
            $styleHint,
            $doNotTranslate,
            $glossary,
        );
    }

    public function hideNewPages(?Site $site): bool
    {
        return (bool)($site?->getSettings()->get('aiTranslator.hideNewPages', false) ?? false) || (bool)$this->get('hideNewPages');
    }

    /**
     * Glossar-Text parsen: Einträge durch Zeilenumbruch oder Semikolon getrennt, je „Begriff=Übersetzung“,
     * optional mit Sprachpräfix „en: Begriff=Übersetzung“ (gilt dann nur für diese Zielsprache).
     *
     * @return array<string, string>
     */
    public static function parseGlossary(string $text, string $targetLanguage = ''): array
    {
        $glossary = [];
        $targetLanguage = strtolower($targetLanguage);
        foreach (preg_split('/[\r\n;]+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            if (preg_match('/^([a-z]{2})(?:[-_][a-z]{2})?\s*:\s*(.+)$/i', $line, $match) && strpos($line, ':') < strpos($line, '=')) {
                if ($targetLanguage !== '' && strtolower($match[1]) !== $targetLanguage) {
                    continue;
                }
                $line = $match[2];
            }
            [$term, $translation] = array_map(trim(...), explode('=', $line, 2));
            if ($term !== '' && $translation !== '') {
                $glossary[$term] = $translation;
            }
        }
        return $glossary;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get('ai_translator');
            return is_array($configuration) ? $configuration : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
