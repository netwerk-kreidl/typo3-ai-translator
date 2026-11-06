<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Einstellungen der Extension. API-Keys können (und sollten) per Umgebungsvariable gesetzt werden –
 * diese haben Vorrang vor der Extension-Konfiguration, damit keine Schlüssel in settings.php landen.
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
     * @return array<string, string> Begriff => Übersetzung
     */
    public function getGlossary(): array
    {
        $glossary = [];
        foreach ($this->getList('glossary', ';') as $entry) {
            [$term, $translation] = array_map(trim(...), explode('=', $entry, 2)) + [1 => ''];
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
