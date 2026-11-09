<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Findet Ausgangsdatensätze einer Seite, ihre Übersetzungen und Inline-Kinder.
 */
final class RecordFinder
{
    public const DOKTYPE_FOLDER = 254;

    /** @var list<string>|null */
    private ?array $topLevelTables = null;

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Übersetzbare Datensätze einer Seite in der Standardsprache: Inhaltselemente sowie eigenständige
     * Datensätze (z. B. Blog-Kategorien in einem Ordner). Inline-Kinder werden über ihre Eltern erfasst.
     *
     * @return list<array{table: string, row: array<string, mixed>}>
     */
    public function findPageRecords(int $pageUid): array
    {
        $records = [];
        foreach ($this->getTopLevelTables() as $table) {
            $capability = $this->getLanguageCapability($table);
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $qb->select('*')->from($table)->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq($capability->getLanguageField()->getName(), 0),
            );
            $sorting = (string)($this->tcaSchemaFactory->get($table)->getRawConfiguration()['sortby'] ?? '');
            if ($table === 'tt_content') {
                $qb->orderBy('colPos')->addOrderBy('sorting');
            } elseif ($sorting !== '') {
                $qb->orderBy($sorting);
            } else {
                $qb->orderBy('uid');
            }
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                $records[] = ['table' => $table, 'row' => $row];
            }
        }
        return $records;
    }

    /**
     * Mehrsprachige Tabellen mit eigenständigen Datensätzen (ohne Seiten, versteckte Tabellen und reine Inline-Kinder).
     *
     * @return list<string>
     */
    public function getTopLevelTables(): array
    {
        if ($this->topLevelTables !== null) {
            return $this->topLevelTables;
        }
        $inlineChildren = [];
        foreach ($this->tcaSchemaFactory->all() as $schema) {
            foreach ($schema->getFields() as $field) {
                $config = $field->getConfiguration();
                if (in_array($field->getType(), ['inline', 'file'], true) && !empty($config['foreign_field'])) {
                    $inlineChildren[(string)($config['foreign_table'] ?? 'sys_file_reference')] = true;
                }
            }
        }
        $tables = ['tt_content'];
        foreach ($this->tcaSchemaFactory->all() as $schema) {
            $name = $schema->getName();
            $ctrl = $schema->getRawConfiguration();
            if ($name === 'pages' || $name === 'tt_content' || !$schema->isLanguageAware() || !empty($ctrl['hideTable'])
                || isset($inlineChildren[$name]) || ($ctrl['rootLevel'] ?? 0) === 1) {
                continue;
            }
            $tables[] = (string)$name;
        }
        return $this->topLevelTables = $tables;
    }

    /**
     * @param array{field: string, table: string, foreignField: string, matchFields: array<string, string>} $relation
     * @return list<array<string, mixed>>
     */
    public function findChildren(array $relation, int $parentUid): array
    {
        $schema = $this->tcaSchemaFactory->get($relation['table']);
        $qb = $this->connectionPool->getQueryBuilderForTable($relation['table']);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $qb->select('*')->from($relation['table'])->where(
            $qb->expr()->eq($relation['foreignField'], $qb->createNamedParameter($parentUid, Connection::PARAM_INT))
        );
        foreach ($relation['matchFields'] as $field => $value) {
            $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
        }
        if ($schema->isLanguageAware()) {
            $qb->andWhere($qb->expr()->eq($this->getLanguageCapability($relation['table'])->getLanguageField()->getName(), 0));
        }
        $sorting = (string)($schema->getRawConfiguration()['sortby'] ?? '');
        $qb->orderBy($sorting !== '' ? $sorting : 'uid');
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Übersetzung eines Datensatzes: verbunden (Übersetzungs-Ursprung) oder frei kopiert (Übersetzungs-Quelle).
     *
     * @param array<string, array<int, int>> $copyMapping Zuordnung aus dem DataHandler (frisch angelegt)
     */
    public function findTranslationUid(string $table, int $sourceUid, int $languageId, array $copyMapping = []): ?int
    {
        if (isset($copyMapping[$table][$sourceUid])) {
            return (int)$copyMapping[$table][$sourceUid];
        }
        if ($table === 'pages') {
            return $this->findPageTranslation($sourceUid, $languageId);
        }
        if (!$this->tcaSchemaFactory->has($table) || !$this->tcaSchemaFactory->get($table)->isLanguageAware()) {
            return null;
        }
        $capability = $this->getLanguageCapability($table);
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $sourceFields = [$capability->getTranslationOriginPointerField()->getName()];
        if ($capability->hasTranslationSourceField()) {
            $sourceFields[] = $capability->getTranslationSourceField()->getName();
        }
        $uid = $qb->select('uid')->from($table)->where(
            $qb->expr()->eq($capability->getLanguageField()->getName(), $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
            $qb->expr()->or(...array_map(
                static fn(string $f) => $qb->expr()->eq($f, $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $sourceFields
            )),
        )->orderBy('uid', 'DESC')->setMaxResults(1)->executeQuery()->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    public function findPageTranslation(int $pageUid, int $languageId): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $uid = $qb->select('uid')->from('pages')->where(
            $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
            $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
        )->setMaxResults(1)->executeQuery()->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    /**
     * Übersetzungsmodus der Inhalte einer Seite: „copyToLanguage“, sobald es freie (nicht verbundene) Inhalte gibt.
     */
    public function usesFreeMode(int $pageUid, int $languageId): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return (int)$qb->count('uid')->from('tt_content')->where(
            $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
            $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
            $qb->expr()->eq('l18n_parent', 0),
        )->executeQuery()->fetchOne() > 0;
    }

    /**
     * Seitenbaum ab einer Seite (Standardsprache) in Baum-Reihenfolge, mit Tiefe.
     *
     * @return list<array{row: array<string, mixed>, depth: int}>
     */
    public function getPageTree(int $rootPageUid, int $maxDepth = 20): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $qb->select('uid', 'pid', 'title', 'nav_title', 'doktype', 'hidden', 'sorting', 'nav_hide')
            ->from('pages')
            ->where($qb->expr()->eq('sys_language_uid', 0))
            ->orderBy('sorting')
            ->executeQuery()->fetchAllAssociative();
        $children = [];
        $root = null;
        foreach ($rows as $row) {
            $children[(int)$row['pid']][] = $row;
            if ((int)$row['uid'] === $rootPageUid) {
                $root = $row;
            }
        }
        if ($root === null) {
            return [];
        }
        $tree = [];
        $walk = static function (array $row, int $depth) use (&$walk, &$tree, $children, $maxDepth): void {
            // Papierkorb (255) auslassen
            if ((int)$row['doktype'] === 255) {
                return;
            }
            $tree[] = ['row' => $row, 'depth' => $depth];
            if ($depth < $maxDepth) {
                foreach ($children[(int)$row['uid']] ?? [] as $child) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($root, 0);
        return $tree;
    }

    /**
     * Metadaten einer Datei in der Standardsprache.
     *
     * @return array<string, mixed>|null
     */
    public function findFileMetadata(int $fileUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from('sys_file_metadata')->where(
            $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, Connection::PARAM_INT)),
            $qb->expr()->eq('sys_language_uid', 0),
        )->setMaxResults(1)->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function getLanguageCapability(string $table): LanguageAwareSchemaCapability
    {
        /** @var LanguageAwareSchemaCapability */
        return $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Language);
    }
}
