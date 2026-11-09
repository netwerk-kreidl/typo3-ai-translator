<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Status;

/**
 * Übersetzungsstand einer Seite in einer Sprache.
 */
final readonly class PageStatus
{
    public const STATE_NOT_TRANSLATED = 'missing';
    public const STATE_OUTDATED = 'outdated';
    public const STATE_INCOMPLETE = 'incomplete';
    public const STATE_CURRENT = 'current';

    /**
     * @param list<OutdatedRecord> $outdated
     * @param list<array{table: string, uid: int, label: string}> $missing Datensätze ohne Übersetzung
     * @param array<string, array<int, true>> $unreviewed KI-übersetzt, noch nicht geprüft: Tabelle => Übersetzungs-UIDs (oberste Datensätze)
     */
    public function __construct(
        public int $pageUid,
        public int $languageId,
        public bool $isFolder,
        public ?int $pageTranslationUid,
        public array $outdated,
        public array $missing,
        public array $unreviewed = [],
    ) {}

    public function getUnreviewedCount(): int
    {
        return array_sum(array_map('count', $this->unreviewed));
    }

    public function isUnreviewed(string $table, int $translationUid): bool
    {
        return isset($this->unreviewed[$table][$translationUid]);
    }

    public function getState(): string
    {
        return match (true) {
            !$this->isFolder && $this->pageTranslationUid === null => self::STATE_NOT_TRANSLATED,
            $this->outdated !== [] => self::STATE_OUTDATED,
            $this->missing !== [] => self::STATE_INCOMPLETE,
            default => self::STATE_CURRENT,
        };
    }

    public function needsUpdate(): bool
    {
        return $this->getState() !== self::STATE_CURRENT;
    }

    /** Anzahl geänderter Felder bzw. neuer Unterelemente */
    public function getOutdatedCount(): int
    {
        return array_sum(array_map(static fn(OutdatedRecord $r) => $r->count(), $this->outdated));
    }

    /**
     * @return array<string, array<int, list<string>>> Tabelle => Übersetzungs-UID des obersten Datensatzes => Feld-Bezeichnungen
     */
    public function getOutdatedByTopRecord(): array
    {
        $result = [];
        foreach ($this->outdated as $record) {
            $labels = array_values($record->fields);
            foreach ($record->newChildren as $children) {
                $labels[] = sprintf('%d neue Unterelemente', count($children['uids']));
            }
            $result[$record->topTable][$record->topTranslationUid] = array_values(array_unique(array_merge(
                $result[$record->topTable][$record->topTranslationUid] ?? [],
                $labels
            )));
        }
        return $result;
    }
}
