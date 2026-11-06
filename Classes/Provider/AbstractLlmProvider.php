<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

/**
 * Gemeinsame Logik für Sprachmodelle (ChatGPT, Claude): Prompt, JSON-Schema, Aufteilung in Pakete.
 * Die Segmente gehen als JSON an das Modell, die Antwort kommt als JSON nach festem Schema zurück.
 */
abstract class AbstractLlmProvider implements TranslationProviderInterface
{
    // Grenzen pro Anfrage, damit die Antwort sicher in das Ausgabelimit passt
    private const MAX_SEGMENTS_PER_REQUEST = 40;
    private const MAX_CHARACTERS_PER_REQUEST = 12000;

    public function translate(TranslationRequest $request): TranslationResult
    {
        $translations = [];
        $inputTokens = $outputTokens = $characters = 0;
        $model = '';
        foreach ($this->chunk($request->segments) as $segments) {
            $payload = [
                'context' => $request->context,
                'segments' => array_map(
                    static fn(string $id, Segment $s): array => array_filter([
                        'id' => $id,
                        'text' => $s->text,
                        'format' => $s->isHtml ? 'html' : 'text',
                        'hint' => $s->hint,
                        'maxLength' => $s->maxLength ?: null,
                    ], static fn($v): bool => $v !== null && $v !== ''),
                    array_keys($segments),
                    $segments
                ),
            ];
            $response = $this->complete(
                $this->buildSystemPrompt($request),
                (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::OUTPUT_SCHEMA
            );
            $model = $response['model'];
            $inputTokens += $response['inputTokens'];
            $outputTokens += $response['outputTokens'];
            $characters += array_sum(array_map(static fn(Segment $s): int => mb_strlen($s->text), $segments));

            $decoded = json_decode($response['text'], true);
            if (!is_array($decoded) || !is_array($decoded['translations'] ?? null)) {
                throw new TranslationException($this->getLabel() . ': Antwort konnte nicht gelesen werden.', 1793130001);
            }
            foreach ($decoded['translations'] as $item) {
                if (isset($item['id'], $item['text'], $segments[$item['id']])) {
                    $translations[(string)$item['id']] = (string)$item['text'];
                }
            }
            $missing = array_diff(array_keys($segments), array_keys($translations));
            if ($missing !== []) {
                throw new TranslationException(sprintf('%s: %d Text(e) fehlen in der Antwort.', $this->getLabel(), count($missing)), 1793130002);
            }
        }
        return new TranslationResult($translations, $this->getIdentifier(), $model, $characters, $inputTokens, $outputTokens);
    }

    /**
     * Ein Aufruf des Modells mit strukturierter Ausgabe.
     *
     * @param array<string, mixed> $schema
     * @return array{text: string, model: string, inputTokens: int, outputTokens: int}
     * @throws TranslationException
     */
    abstract protected function complete(string $system, string $user, array $schema): array;

    protected const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'translations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'text'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['translations'],
        'additionalProperties' => false,
    ];

    protected function buildSystemPrompt(TranslationRequest $request): string
    {
        $l = $request->languages;
        $style = $request->style;
        $lines = [
            sprintf(
                'You translate website content from %s into %s for publication on the website. Write natural, idiomatic %s that reads as if it had been written for native speakers, not a word-for-word rendering.',
                $l->sourceName ?: $l->sourceLanguage,
                $l->targetName ?: $l->targetLanguage,
                $l->targetName ?: $l->targetLanguage
            ),
            '',
            'Input is JSON with a "context" and a list of "segments". Translate every segment and return each "id" exactly once with its translated "text".',
            '- Segments with "format": "html" contain HTML. Keep all tags, attributes and entities exactly as they are and translate only the visible text. Placeholders like {{ATTR_3}} must stay unchanged.',
            '- Segments with "format": "text" are plain text. Keep line breaks.',
            '- Do not translate URLs, e-mail addresses, phone numbers or code.',
            '- "hint" says what the text is (for example a heading or a link text); use it to choose length and tone. Headings stay short.',
            '- If "maxLength" is given, the translation must not be longer than that many characters.',
            '- Translate even very short segments (single words, button labels) in the context of the page.',
        ];
        $lines[] = match ($style->formality) {
            'formal' => '- Address the reader formally where the target language distinguishes it (e.g. Sie, vous, usted, Lei).',
            'informal' => '- Address the reader informally where the target language distinguishes it (e.g. du, tu, tú).',
            default => '- Keep the level of formality of the source text.',
        };
        if ($style->styleHint !== '') {
            $lines[] = '- Tone and audience: ' . $style->styleHint;
        }
        if ($style->doNotTranslate !== []) {
            $lines[] = '- Keep these terms unchanged: ' . implode(', ', $style->doNotTranslate);
        }
        foreach ($style->glossary as $term => $translation) {
            $lines[] = sprintf('- Always translate "%s" as "%s".', $term, $translation);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, Segment> $segments
     * @return iterable<array<string, Segment>>
     */
    private function chunk(array $segments): iterable
    {
        $chunk = [];
        $length = 0;
        foreach ($segments as $id => $segment) {
            $segmentLength = mb_strlen($segment->text);
            if ($chunk !== [] && (count($chunk) >= self::MAX_SEGMENTS_PER_REQUEST || $length + $segmentLength > self::MAX_CHARACTERS_PER_REQUEST)) {
                yield $chunk;
                $chunk = [];
                $length = 0;
            }
            $chunk[$id] = $segment;
            $length += $segmentLength;
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
