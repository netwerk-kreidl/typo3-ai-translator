<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Service;

use Netwerk\AiTranslator\Provider\Segment;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;

/**
 * Zerlegt Feldwerte in übersetzbare Segmente und setzt die Übersetzungen wieder zusammen:
 *  - Text-Felder: unverändert
 *  - Rich-Text: HTML bleibt erhalten; title-/alt-Attribute werden als eigene Segmente übersetzt
 *    (DeepL übersetzt Attribute sonst nicht), im HTML stehen dafür Platzhalter {{ATTR_n}}
 *  - Link-Felder (t3://… _blank - "Titel"): nur der Linktitel wird übersetzt
 */
final class SegmentCodec
{
    public function __construct(private readonly TypoLinkCodecService $typoLinkCodec) {}

    /**
     * @return array{segments: array<string, Segment>, template: array<string, mixed>}
     *         segments: Suffix => Segment, template: Infos zum Zusammensetzen
     */
    public function encode(TranslatableField $field, string $value, string $hint): array
    {
        if (trim(strip_tags($value)) === '' && $field->kind !== TranslatableField::KIND_LINK) {
            return ['segments' => [], 'template' => []];
        }
        switch ($field->kind) {
            case TranslatableField::KIND_LINK:
                $parts = $this->typoLinkCodec->decode($value);
                $title = trim((string)($parts['title'] ?? ''));
                if ($title === '') {
                    return ['segments' => [], 'template' => []];
                }
                return [
                    'segments' => ['' => new Segment($title, false, $hint . ' – Linktitel')],
                    'template' => ['kind' => 'link', 'parts' => $parts],
                ];

            case TranslatableField::KIND_HTML:
                $segments = [];
                $attributes = [];
                $html = preg_replace_callback(
                    '/(\s(?:title|alt)=")([^"]+)(")/iu',
                    static function (array $m) use (&$segments, &$attributes, $hint): string {
                        $value = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if (trim($value) === '' || str_starts_with($value, '{{')) {
                            return $m[0];
                        }
                        $n = count($attributes);
                        $attributes[$n] = true;
                        $segments['attr' . $n] = new Segment($value, false, $hint . ' – Link-/Bildtitel');
                        return $m[1] . '{{ATTR_' . $n . '}}' . $m[3];
                    },
                    $value
                ) ?? $value;
                $segments = ['' => new Segment($html, true, $hint, $field->maxLength)] + $segments;
                return ['segments' => $segments, 'template' => ['kind' => 'html', 'attributes' => count($attributes)]];

            default:
                return [
                    'segments' => ['' => new Segment($value, false, $hint, $field->maxLength)],
                    'template' => ['kind' => 'text'],
                ];
        }
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, string> $translations Suffix => Übersetzung
     */
    public function decode(array $template, array $translations, int $maxLength = 0): string
    {
        $main = $translations[''] ?? '';
        $value = match ($template['kind'] ?? '') {
            'link' => $this->typoLinkCodec->encode(['title' => $main] + (array)$template['parts']),
            'html' => (string)preg_replace_callback(
                '/\{\{ATTR_(\d+)\}\}/',
                static fn(array $m): string => htmlspecialchars($translations['attr' . $m[1]] ?? '', ENT_QUOTES | ENT_HTML5),
                $main
            ),
            default => $main,
        };
        if ($maxLength > 0 && ($template['kind'] ?? '') === 'text' && mb_strlen($value) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
        }
        return $value;
    }
}
