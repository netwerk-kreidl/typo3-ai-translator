<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Status;

use Netwerk\AiTranslator\Domain\Repository\StateRepository;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Service\FieldResolver;
use Netwerk\AiTranslator\Service\RecordFinder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Übersetzungsstand der Datei-Metadaten (Titel, Alternativtext, Beschreibung).
 */
final class FileStatusService
{
    public const STATE_EMPTY = 'empty';
    public const STATE_MISSING = 'missing';
    public const STATE_OUTDATED = 'outdated';
    public const STATE_CURRENT = 'current';

    /** Höchstzahl Dateien in der Übersicht */
    public const LIMIT = 1000;

    public function __construct(
        private readonly RecordFinder $finder,
        private readonly FieldResolver $fieldResolver,
        private readonly StatusService $statusService,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly StateRepository $stateRepository,
    ) {}

    /**
     * @return array{state: string, metaUid: int, translationUid: int, fields: array<string, string>, source: array<string, string>, translation: array<string, string>, unreviewed: bool}
     */
    public function getStatus(int $fileUid, int $languageId): array
    {
        $result = ['state' => self::STATE_EMPTY, 'metaUid' => 0, 'translationUid' => 0, 'fields' => [], 'source' => [], 'translation' => [], 'unreviewed' => false];
        $metadata = $this->finder->findFileMetadata($fileUid);
        if ($metadata === null) {
            return $result;
        }
        $result['metaUid'] = (int)$metadata['uid'];
        foreach ($this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata) as $name => $field) {
            $value = trim(strip_tags((string)($metadata[$name] ?? '')));
            if ($value !== '') {
                $result['source'][$field->label] = $value;
            }
        }
        if ($result['source'] === []) {
            return $result;
        }
        $translationUid = $this->finder->findTranslationUid('sys_file_metadata', (int)$metadata['uid'], $languageId);
        $translation = $translationUid !== null ? BackendUtility::getRecord('sys_file_metadata', $translationUid) : null;
        if ($translation === null) {
            $result['state'] = self::STATE_MISSING;
            return $result;
        }
        $result['translationUid'] = (int)$translationUid;
        foreach ($this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata) as $name => $field) {
            $value = trim(strip_tags((string)($translation[$name] ?? '')));
            if ($value !== '') {
                $result['translation'][$field->label] = $value;
            }
        }
        $result['fields'] = $this->statusService->getChangedFields('sys_file_metadata', $metadata, $translation);
        $result['unreviewed'] = $this->stateRepository->isUnreviewed('sys_file_metadata', (int)$translationUid);
        $result['state'] = $result['fields'] !== [] ? self::STATE_OUTDATED : self::STATE_CURRENT;
        return $result;
    }

    /**
     * Dateien eines Ordners (optional mit Unterordnern), nach Pfad sortiert.
     *
     * @return list<array{uid: int, identifier: string, name: string, extension: string, storage: int}>
     */
    public function findFiles(int $storageUid, string $folderIdentifier, bool $recursive): array
    {
        $folderIdentifier = rtrim($folderIdentifier, '/') . '/';
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $qb->select('uid', 'identifier', 'name', 'extension', 'storage')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('missing', 0),
                $qb->expr()->like('identifier', $qb->createNamedParameter($qb->escapeLikeWildcards($folderIdentifier) . '%')),
                // Systemdateien (z. B. index.html in Upload-Ordnern) auslassen
                $qb->expr()->neq('name', $qb->createNamedParameter('index.html')),
            )
            ->orderBy('identifier')
            ->setMaxResults(self::LIMIT);
        if (!$recursive) {
            $qb->andWhere($qb->expr()->notLike('identifier', $qb->createNamedParameter($qb->escapeLikeWildcards($folderIdentifier) . '%/%')));
        }
        $files = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if (str_contains((string)$row['identifier'], '/_processed_/') || str_contains((string)$row['identifier'], '/_temp_/')) {
                continue;
            }
            $files[] = [
                'uid' => (int)$row['uid'],
                'identifier' => (string)$row['identifier'],
                'name' => (string)$row['name'],
                'extension' => strtolower((string)$row['extension']),
                'storage' => (int)$row['storage'],
            ];
        }
        return $files;
    }

    /**
     * Alle Ordner eines Speichers, die Dateien enthalten (inkl. übergeordneter Ordner).
     *
     * @return list<string>
     */
    public function getFolders(int $storageUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $folders = ['/' => true];
        foreach ($qb->select('identifier')->from('sys_file')->where(
            $qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)),
            $qb->expr()->eq('missing', 0),
        )->executeQuery()->fetchFirstColumn() as $identifier) {
            $parts = explode('/', trim(dirname((string)$identifier), '/'));
            $path = '/';
            foreach ($parts as $part) {
                if ($part === '' || $part === '_processed_' || $part === '_temp_') {
                    break;
                }
                $path .= $part . '/';
                $folders[$path] = true;
            }
        }
        $folders = array_keys($folders);
        sort($folders);
        return $folders;
    }

    /**
     * Speicher (Storages) mit indexierten Dateien.
     *
     * @return array<int, string> uid => Name
     */
    public function getStorages(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $result = [];
        foreach ($qb->select('uid', 'name')->from('sys_file_storage')->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
            $result[(int)$row['uid']] = (string)$row['name'];
        }
        return $result;
    }

    /**
     * Zielsprachen über alle Websites (Dateien gehören zu keiner Seite), nur mit Berechtigung.
     *
     * @return array<int, SiteLanguage>
     */
    public function getLanguages(): array
    {
        $languages = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getLanguages() as $language) {
                $id = $language->getLanguageId();
                if ($id > 0 && !isset($languages[$id]) && Permission::canEditLanguage($id)) {
                    $languages[$id] = $language;
                }
            }
        }
        ksort($languages);
        return $languages;
    }
}
