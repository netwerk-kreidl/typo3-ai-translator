<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Status;

/**
 * Eine Übersetzung, deren Ausgangstext sich seit der Übersetzung geändert hat.
 */
final readonly class OutdatedRecord
{
    /**
     * @param array<string, string> $fields Geänderte Felder (Name => Bezeichnung)
     * @param array<string, array{table: string, uids: list<int>}> $newChildren Neue, noch nicht übersetzte Unterelemente je Feld
     */
    public function __construct(
        public string $table,
        public int $sourceUid,
        public int $translationUid,
        public string $label,
        public array $fields,
        public array $newChildren,
        public string $topTable,
        public int $topTranslationUid,
    ) {}

    public function count(): int
    {
        $count = count($this->fields);
        foreach ($this->newChildren as $children) {
            $count += count($children['uids']);
        }
        return $count;
    }
}
