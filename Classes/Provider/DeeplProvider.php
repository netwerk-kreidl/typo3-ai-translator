<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Registry;

/**
 * DeepL API v2. Alle Texte werden als HTML übertragen (tag_handling=html), damit Tags erhalten bleiben
 * und „Nicht übersetzen“-Begriffe per <span translate="no"> geschützt werden können.
 * Free-Keys (Endung „:fx“) nutzen automatisch den Free-Endpunkt.
 *
 * Glossar: Einträge aus den Einstellungen werden als DeepL-Glossar angelegt (einmal je Inhalt und
 * Sprachpaar, ID in der TYPO3-Registry gemerkt) und bei jeder Übersetzung mitgegeben.
 */
final class DeeplProvider implements TranslationProviderInterface
{
    private const MAX_TEXTS_PER_REQUEST = 50;
    private const MAX_BYTES_PER_REQUEST = 100000;
    private const REGISTRY_NAMESPACE = 'ai_translator';
    /** So viele Glossare je Sprachpaar bleiben bei DeepL bestehen (z. B. für mehrere Websites) */
    private const GLOSSARIES_PER_PAIR = 5;

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly RetryingHttpClient $httpClient,
        private readonly Registry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function getLabel(): string
    {
        return 'DeepL';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('deeplApiKey') !== '';
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $prepared = [];
        foreach ($request->segments as $id => $segment) {
            $prepared[$id] = $this->protectTerms(
                $segment->isHtml ? $segment->text : nl2br(htmlspecialchars($segment->text, ENT_QUOTES | ENT_HTML5), false),
                $request->style->doNotTranslate
            );
        }

        $translations = [];
        $characters = 0;
        $glossaryId = $this->getGlossaryId($request);
        foreach ($this->chunk($prepared) as $chunk) {
            $texts = $this->call(array_values($chunk), $request, $glossaryId);
            foreach (array_keys($chunk) as $i => $id) {
                $text = $this->unprotectTerms($texts[$i] ?? '');
                $translations[$id] = $request->segments[$id]->isHtml
                    ? $text
                    : html_entity_decode(preg_replace('#<br\s*/?>\R?#i', "\n", $text) ?? $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $characters += mb_strlen($request->segments[$id]->text);
            }
        }
        return new TranslationResult($translations, $this->getIdentifier(), 'deepl-v2', $characters);
    }

    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private function call(array $texts, TranslationRequest $request, ?string &$glossaryId, bool $retried = false): array
    {
        $apiKey = $this->settings->get('deeplApiKey');
        $endpoint = $this->baseUrl() . '/v2/translate';
        $body = array_filter([
            'text' => $texts,
            'source_lang' => strtoupper($request->languages->sourceLanguage),
            'target_lang' => $this->targetLanguage($request->languages),
            'tag_handling' => 'html',
            'formality' => match ($request->style->formality) {
                'formal' => 'prefer_more',
                'informal' => 'prefer_less',
                default => null,
            },
            'context' => $request->context ?: null,
            'glossary_id' => $glossaryId,
        ]);
        try {
            $response = $this->httpClient->request($endpoint, 'POST', [
                'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $apiKey, 'Content-Type' => 'application/json'],
                'json' => $body,
                'timeout' => 120,
            ]);
        } catch (\Throwable $e) {
            throw new TranslationException('DeepL: Keine Verbindung zur API.', 1793130301, $e);
        }
        $status = $response->getStatusCode();
        $data = json_decode((string)$response->getBody(), true) ?: [];
        if ($status === 404 && $glossaryId !== null && !$retried) {
            // Glossar wurde bei DeepL gelöscht: neu anlegen und einmal wiederholen
            $this->forgetGlossary($request, $glossaryId);
            $glossaryId = $this->getGlossaryId($request);
            return $this->call($texts, $request, $glossaryId, true);
        }
        if ($status !== 200) {
            throw new TranslationException(match ($status) {
                403 => 'DeepL: Der API-Key ist ungültig.',
                456 => 'DeepL: Das Zeichenkontingent ist aufgebraucht.',
                429 => 'DeepL: Zu viele Anfragen – bitte später erneut versuchen.',
                400 => 'DeepL: ' . (string)($data['message'] ?? 'Ungültige Anfrage') . ' (Sprache wird ggf. nicht unterstützt)',
                default => 'DeepL: ' . (string)($data['message'] ?? $response->getReasonPhrase()),
            }, 1793130302);
        }
        return array_map(static fn(array $t): string => (string)($t['text'] ?? ''), $data['translations'] ?? []);
    }

    /**
     * ID des DeepL-Glossars für die Einträge dieser Anfrage (angelegt, falls noch nicht vorhanden).
     * Schlägt das Anlegen fehl (z. B. Sprachpaar ohne Glossar-Unterstützung), wird ohne Glossar übersetzt.
     */
    private function getGlossaryId(TranslationRequest $request): ?string
    {
        $entries = self::buildGlossaryEntries($request->style->glossary);
        if ($entries === '') {
            return null;
        }
        $source = strtolower($request->languages->sourceLanguage);
        $target = strtolower($request->languages->targetLanguage);
        $pairKey = 'deepl-glossaries-' . md5($this->baseUrl() . '|' . $source . '|' . $target);
        $hash = md5($entries);
        /** @var array<string, string> $known Hash => Glossar-ID (älteste zuerst) */
        $known = (array)$this->registry->get(self::REGISTRY_NAMESPACE, $pairKey, []);
        if (isset($known[$hash])) {
            return $known[$hash];
        }

        $response = $this->httpClient->request($this->baseUrl() . '/v2/glossaries', 'POST', [
            'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $this->settings->get('deeplApiKey'), 'Content-Type' => 'application/json'],
            'json' => [
                'name' => sprintf('TYPO3 ai_translator %s-%s %s', $source, $target, substr($hash, 0, 8)),
                'source_lang' => $source,
                'target_lang' => $target,
                'entries' => $entries,
                'entries_format' => 'tsv',
            ],
            'timeout' => 60,
        ]);
        $data = json_decode((string)$response->getBody(), true) ?: [];
        if (!in_array($response->getStatusCode(), [200, 201], true) || empty($data['glossary_id'])) {
            $this->logger->warning('DeepL glossary could not be created ({status}): {message}', [
                'status' => $response->getStatusCode(),
                'message' => (string)($data['message'] ?? $response->getReasonPhrase()),
            ]);
            return null;
        }
        $known[$hash] = (string)$data['glossary_id'];
        while (count($known) > self::GLOSSARIES_PER_PAIR) {
            $this->deleteGlossary((string)array_shift($known));
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, $pairKey, $known);
        return $known[$hash];
    }

    private function forgetGlossary(TranslationRequest $request, string $glossaryId): void
    {
        $pairKey = 'deepl-glossaries-' . md5($this->baseUrl() . '|' . strtolower($request->languages->sourceLanguage) . '|' . strtolower($request->languages->targetLanguage));
        $known = (array)$this->registry->get(self::REGISTRY_NAMESPACE, $pairKey, []);
        $this->registry->set(self::REGISTRY_NAMESPACE, $pairKey, array_filter($known, static fn($id): bool => $id !== $glossaryId));
    }

    private function deleteGlossary(string $glossaryId): void
    {
        try {
            $this->httpClient->request($this->baseUrl() . '/v2/glossaries/' . rawurlencode($glossaryId), 'DELETE', [
                'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $this->settings->get('deeplApiKey')],
                'timeout' => 30,
            ]);
        } catch (\Throwable) {
            // nicht kritisch
        }
    }

    /**
     * Glossar im TSV-Format von DeepL („Begriff<TAB>Übersetzung“ je Zeile).
     *
     * @param array<string, string> $glossary
     */
    public static function buildGlossaryEntries(array $glossary): string
    {
        $lines = [];
        foreach ($glossary as $term => $translation) {
            $term = trim(preg_replace('/\s+/u', ' ', (string)$term) ?? '');
            $translation = trim(preg_replace('/\s+/u', ' ', $translation) ?? '');
            if ($term !== '' && $translation !== '') {
                $lines[] = $term . "\t" . $translation;
            }
        }
        return implode("\n", $lines);
    }

    private function baseUrl(): string
    {
        return str_ends_with($this->settings->get('deeplApiKey'), ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    }

    /**
     * DeepL-Zielsprachen mit Varianten: EN-US/EN-GB, PT-BR/PT-PT, ZH-HANS.
     */
    private function targetLanguage(LanguagePair $languages): string
    {
        $language = strtoupper($languages->targetLanguage);
        return match ($language) {
            'EN' => $languages->targetRegion === 'US' ? 'EN-US' : 'EN-GB',
            'PT' => $languages->targetRegion === 'BR' ? 'PT-BR' : 'PT-PT',
            'ZH' => 'ZH-HANS',
            default => $language,
        };
    }

    /**
     * @param list<string> $terms
     */
    private function protectTerms(string $html, array $terms): string
    {
        foreach ($terms as $term) {
            // nur im Text, nicht in Tags/Attributen
            $html = preg_replace_callback(
                '/(<[^>]*>)|(' . preg_quote(htmlspecialchars($term, ENT_QUOTES | ENT_HTML5), '/') . ')/u',
                static fn(array $m): string => $m[1] !== '' ? $m[1] : '<span translate="no" data-ait="1">' . $m[2] . '</span>',
                $html
            ) ?? $html;
        }
        return $html;
    }

    private function unprotectTerms(string $html): string
    {
        return preg_replace('#<span translate="no" data-ait="1">(.*?)</span>#su', '$1', $html) ?? $html;
    }

    /**
     * @param array<string, string> $texts
     * @return iterable<array<string, string>>
     */
    private function chunk(array $texts): iterable
    {
        $chunk = [];
        $bytes = 0;
        foreach ($texts as $id => $text) {
            if ($chunk !== [] && (count($chunk) >= self::MAX_TEXTS_PER_REQUEST || $bytes + strlen($text) > self::MAX_BYTES_PER_REQUEST)) {
                yield $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[$id] = $text;
            $bytes += strlen($text);
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
