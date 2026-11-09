<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Status;

use Netwerk\AiTranslator\Domain\Repository\StateRepository;
use Netwerk\AiTranslator\Service\FieldResolver;
use Netwerk\AiTranslator\Service\RecordFinder;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Ermittelt, welche Übersetzungen einer Seite veraltet sind oder fehlen.
 *
 * Ein Feld gilt als veraltet, wenn sich der Ausgangstext seit der Übersetzung geändert hat:
 *  - verbundene Übersetzungen: Vergleich mit dem Stand, den TYPO3 beim Speichern der Übersetzung festhält
 *    („l10n_diffsource“) – funktioniert damit auch für von Hand übersetzte Inhalte. Speichert jemand die
 *    Übersetzung im Formular, gilt sie wie im TYPO3-Standard als geprüft.
 *  - freie Übersetzungen (Kopien): Vergleich mit dem Fingerabdruck, den die KI-Übersetzung gespeichert hat;
 *    wurde das übersetzte Feld seitdem von Hand geändert, gilt es als erledigt.
 */
final class StatusService
{
    /** @var array<string, PageStatus> */
    private array $cache = [];

    public function __construct(
        private readonly RecordFinder $finder,
        private readonly FieldResolver $fieldResolver,
        private readonly StateRepository $stateRepository,
    ) {}

    public function getPageStatus(int $pageUid, int $languageId, bool $fresh = false): PageStatus
    {
        $key = $pageUid . '-' . $languageId;
        if (!$fresh && isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $page = BackendUtility::getRecord('pages', $pageUid) ?? [];
        $isFolder = (int)($page['doktype'] ?? 0) === RecordFinder::DOKTYPE_FOLDER;
        $pageTranslationUid = $isFolder ? null : $this->finder->findPageTranslation($pageUid, $languageId);

        $outdated = [];
        $missing = [];
        $records = $this->finder->findPageRecords($pageUid);
        if (!$isFolder && $pageTranslationUid === null) {
            foreach ($records as $record) {
                $missing[] = ['table' => $record['table'], 'uid' => (int)$record['row']['uid'], 'label' => $this->label($record['table'], $record['row'])];
            }
            return $this->cache[$key] = new PageStatus($pageUid, $languageId, false, null, [], $missing);
        }

        if ($pageTranslationUid !== null && $page !== []) {
            $this->check('pages', $page, $pageTranslationUid, $languageId, 'pages', $pageTranslationUid, $outdated);
        }
        foreach ($records as $record) {
            $table = $record['table'];
            $sourceUid = (int)$record['row']['uid'];
            $translationUid = $this->finder->findTranslationUid($table, $sourceUid, $languageId);
            if ($translationUid === null) {
                $missing[] = ['table' => $table, 'uid' => $sourceUid, 'label' => $this->label($table, $record['row'])];
                continue;
            }
            $this->check($table, $record['row'], $translationUid, $languageId, $table, $translationUid, $outdated);
        }
        $unreviewed = $this->stateRepository->findUnreviewed($pageUid, $languageId);
        return $this->cache[$key] = new PageStatus($pageUid, $languageId, $isFolder, $pageTranslationUid, $outdated, $missing, $unreviewed);
    }

    /**
     * Felder, die sich geändert haben (Name => Bezeichnung) – leer, wenn aktuell.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $translation
     * @return array<string, string>
     */
    public function getChangedFields(string $table, array $source, array $translation): array
    {
        $capability = $this->finder->getLanguageCapability($table);
        $diffSource = null;
        if ($capability->hasDiffSourceField() && (int)($translation[$capability->getTranslationOriginPointerField()->getName()] ?? 0) > 0) {
            $decoded = json_decode((string)($translation[$capability->getDiffSourceField()->getName()] ?? ''), true);
            $diffSource = is_array($decoded) ? $decoded : null;
        }
        $states = $this->stateRepository->findByTranslation($table, (int)$translation['uid']);

        $changed = [];
        foreach ($this->fieldResolver->getTranslatableFields($table, $source) as $name => $field) {
            $current = (string)($source[$name] ?? '');
            if ($diffSource !== null && array_key_exists($name, $diffSource)) {
                $isChanged = self::normalize((string)$diffSource[$name]) !== self::normalize($current);
            } elseif (isset($states[$name])) {
                $isChanged = StateRepository::hash($current) !== $states[$name]['source_hash']
                    && StateRepository::hash((string)($translation[$name] ?? '')) === $states[$name]['translation_hash'];
            } else {
                continue;
            }
            if ($isChanged) {
                $changed[$name] = $field->label;
            }
        }
        return $changed;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<OutdatedRecord> $outdated
     */
    private function check(string $table, array $source, int $translationUid, int $languageId, string $topTable, int $topTranslationUid, array &$outdated, int $depth = 0): void
    {
        $translation = BackendUtility::getRecord($table, $translationUid);
        if ($translation === null) {
            return;
        }
        $changed = $this->getChangedFields($table, $source, $translation);

        $newChildren = [];
        $capability = $this->finder->getLanguageCapability($table);
        $isConnected = (int)($translation[$capability->getTranslationOriginPointerField()->getName()] ?? 0) > 0;
        if ($depth < 4) {
            foreach ($this->fieldResolver->getChildRelations($table, $source) as $relation) {
                foreach ($this->finder->findChildren($relation, (int)$source['uid']) as $child) {
                    $childTranslationUid = $this->finder->findTranslationUid($relation['table'], (int)$child['uid'], $languageId);
                    if ($childTranslationUid === null) {
                        // Neue Unterelemente lassen sich nur bei verbundenen Übersetzungen automatisch ergänzen
                        if ($isConnected) {
                            $newChildren[$relation['field']]['table'] = $relation['table'];
                            $newChildren[$relation['field']]['uids'][] = (int)$child['uid'];
                        }
                        continue;
                    }
                    $this->check($relation['table'], $child, $childTranslationUid, $languageId, $topTable, $topTranslationUid, $outdated, $depth + 1);
                }
            }
        }

        if ($changed !== [] || $newChildren !== []) {
            $outdated[] = new OutdatedRecord(
                $table,
                (int)$source['uid'],
                $translationUid,
                $this->label($table, $source),
                $changed,
                $newChildren,
                $topTable,
                $topTranslationUid,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function label(string $table, array $row): string
    {
        $title = trim(strip_tags((string)BackendUtility::getRecordTitle($table, $row)));
        $type = $table === 'pages' ? 'Seiteneigenschaften' : $this->fieldResolver->getRecordTypeLabel($table, $row);
        return $title !== '' && $title !== '[' . ($row['uid'] ?? '') . ']' ? $type . ': ' . $title : $type;
    }

    private static function normalize(string $value): string
    {
        return trim(str_replace("\r\n", "\n", $value));
    }
}
