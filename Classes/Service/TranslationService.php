<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Service;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Domain\Repository\LogRepository;
use Netwerk\AiTranslator\Domain\Repository\StateRepository;
use Netwerk\AiTranslator\Provider\LanguagePair;
use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Provider\Segment;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Provider\TranslationRequest;
use Netwerk\AiTranslator\Provider\TranslationResult;
use Netwerk\AiTranslator\Provider\TranslationStyle;
use TYPO3\CMS\Backend\Localization\LocalizationMode;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Übersetzt Seiten und Datensätze mit einem KI-Anbieter.
 *
 * Ablauf: 1. Ausgangstexte sammeln (inkl. Inline-Kindern wie FAQ-Einträgen und Bildreferenzen),
 * 2. übersetzen lassen, 3. erst dann die Übersetzungen mit dem TYPO3-eigenen DataHandler anlegen
 * („localize“ bzw. „copyToLanguage“) und die Texte eintragen. Schlägt der Anbieter fehl, wird nichts angelegt.
 */
final class TranslationService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly FieldResolver $fieldResolver,
        private readonly SegmentCodec $codec,
        private readonly ExtensionSettings $settings,
        private readonly SiteFinder $siteFinder,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly LogRepository $logRepository,
        private readonly StateRepository $stateRepository,
    ) {}

    /**
     * @param list<int> $contentUids Inhaltselemente (tt_content), die mitübersetzt werden
     * @return array{createdPage: bool, records: int}
     * @throws TranslationException
     */
    public function translatePage(int $pageUid, int $targetLanguageId, array $contentUids, string $providerId, LocalizationMode $mode): array
    {
        $page = BackendUtility::getRecord('pages', $pageUid);
        if ($page === null) {
            throw new TranslationException(sprintf('Seite %d nicht gefunden.', $pageUid), 1793130501);
        }
        $site = $this->siteFinder->getSiteByPageId($pageUid);
        $pageTranslationExists = $this->findPageTranslation($pageUid, $targetLanguageId) !== null;

        $items = [];
        if (!$pageTranslationExists) {
            $this->collect('pages', $page, $items);
        }
        foreach ($contentUids as $contentUid) {
            $row = BackendUtility::getRecord('tt_content', $contentUid);
            if ($row !== null) {
                $this->collect('tt_content', $row, $items);
            }
        }

        $commands = [];
        if (!$pageTranslationExists) {
            // Seiten werden immer als verbundene Übersetzung angelegt (wie im TYPO3-Standard)
            $commands['pages'][$pageUid] = ['localize' => $targetLanguageId];
        }
        foreach ($contentUids as $contentUid) {
            $commands['tt_content'][$contentUid] = [$mode->getDataHandlerCommand() => $targetLanguageId];
        }

        $records = $this->run($items, $commands, $site, $page, $targetLanguageId, $providerId, 'pages', $pageUid);
        return ['createdPage' => !$pageTranslationExists, 'records' => $records];
    }

    /**
     * Einzelner Datensatz (z. B. ein Inhaltselement aus der Listenansicht).
     *
     * @throws TranslationException
     */
    public function translateRecord(string $table, int $uid, int $targetLanguageId, string $providerId, LocalizationMode $mode): int
    {
        $row = BackendUtility::getRecord($table, $uid);
        if ($row === null) {
            throw new TranslationException(sprintf('Datensatz %s:%d nicht gefunden.', $table, $uid), 1793130502);
        }
        $pageUid = (int)$row['pid'];
        $page = BackendUtility::getRecord('pages', $pageUid) ?? ['uid' => $pageUid, 'title' => ''];
        $site = $this->siteFinder->getSiteByPageId($pageUid);
        $items = [];
        $this->collect($table, $row, $items);
        $commands = [$table => [$uid => [$mode->getDataHandlerCommand() => $targetLanguageId]]];
        $this->run($items, $commands, $site, $page, $targetLanguageId, $providerId, $table, $uid);
        return $this->findTranslationUid($table, $uid, $targetLanguageId, []) ?? 0;
    }

    /**
     * @param list<array{table: string, row: array<string, mixed>, fields: array<string, TranslatableField>, hint: string}> $items
     * @param array<string, array<int, array<string, int>>> $commands
     * @param array<string, mixed> $page
     */
    private function run(array $items, array $commands, Site $site, array $page, int $targetLanguageId, string $providerId, string $logTable, int $logUid): int
    {
        @set_time_limit(600);
        $provider = $this->providers->get($providerId);
        if (!$provider->isConfigured()) {
            throw new TranslationException(sprintf('%s ist nicht eingerichtet (API-Key fehlt).', $provider->getLabel()), 1793130503);
        }
        $languages = LanguagePair::fromSiteLanguages($site->getDefaultLanguage(), $site->getLanguageById($targetLanguageId));

        // 1. Segmente bilden
        $segments = [];
        $templates = [];
        foreach ($items as $i => $item) {
            foreach ($item['fields'] as $field) {
                $encoded = $this->codec->encode($field, (string)($item['row'][$field->name] ?? ''), $item['hint'] . ' – ' . $field->label);
                if ($encoded['segments'] === []) {
                    continue;
                }
                $templates[$i][$field->name] = $encoded['template'];
                foreach ($encoded['segments'] as $suffix => $segment) {
                    $segments[$this->segmentId($i, $field->name, (string)$suffix)] = $segment;
                }
            }
        }

        // 2. Übersetzen (vor dem Anlegen – bei Fehlern bleibt alles unverändert)
        $result = new TranslationResult([], $providerId);
        if ($segments !== []) {
            $request = new TranslationRequest(
                $segments,
                $languages,
                new TranslationStyle(
                    in_array($this->settings->get('formality'), ['formal', 'informal'], true) ? $this->settings->get('formality') : 'default',
                    $this->settings->get('styleHint'),
                    $this->settings->getList('doNotTranslate'),
                    $this->settings->getGlossary(),
                ),
                sprintf('Website „%s“, Seite „%s“', $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(), (string)($page['title'] ?? '')),
            );
            try {
                $result = $provider->translate($request);
            } catch (TranslationException $e) {
                $this->log($page, $logTable, $logUid, $targetLanguageId, $providerId, '', count($items), count($segments), 0, 0, 0, 'error', $e->getMessage());
                throw $e;
            }
        }

        // 3. Übersetzungen anlegen
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commands);
        $dataHandler->process_cmdmap();
        if ($dataHandler->errorLog !== []) {
            throw new TranslationException(implode("\n", $dataHandler->errorLog), 1793130504);
        }
        $copyMapping = $dataHandler->copyMappingArray_merged;

        // 4. Texte eintragen
        $datamap = [];
        $state = [];
        $newPageTranslationUid = null;
        foreach ($items as $i => $item) {
            $table = $item['table'];
            $sourceUid = (int)$item['row']['uid'];
            $translationUid = $table === 'pages'
                ? $this->findPageTranslation($sourceUid, $targetLanguageId)
                : $this->findTranslationUid($table, $sourceUid, $targetLanguageId, $copyMapping);
            if ($translationUid === null) {
                continue;
            }
            if ($table === 'pages') {
                $newPageTranslationUid = $translationUid;
            }
            foreach ($templates[$i] ?? [] as $fieldName => $template) {
                $parts = [];
                $prefix = $this->segmentId($i, $fieldName, '');
                foreach ($result->translations as $id => $translation) {
                    if ($id === $prefix || str_starts_with($id, $prefix . ':')) {
                        $parts[substr($id, strlen($prefix) + ($id === $prefix ? 0 : 1))] = $translation;
                    }
                }
                if (!isset($parts[''])) {
                    continue;
                }
                $value = $this->codec->decode($template, $parts, $item['fields'][$fieldName]->maxLength);
                $datamap[$table][$translationUid][$fieldName] = $value;
                $state[] = [$table, $translationUid, $sourceUid, $fieldName, (string)($item['row'][$fieldName] ?? ''), $value];
            }
        }
        if ($datamap !== []) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($datamap, []);
            $dataHandler->process_datamap();
            if ($dataHandler->errorLog !== []) {
                throw new TranslationException(implode("\n", $dataHandler->errorLog), 1793130505);
            }
        }

        // 5. URL (Slug) einer neu angelegten Seitenübersetzung aus dem übersetzten Titel erzeugen
        if ($newPageTranslationUid !== null) {
            $this->regenerateSlug($newPageTranslationUid);
        }

        foreach ($state as [$table, $translationUid, $sourceUid, $field, $sourceValue, $value]) {
            $this->stateRepository->save($table, $translationUid, $sourceUid, (int)$page['uid'], $targetLanguageId, $field, $sourceValue, $value, $providerId);
        }
        $this->log($page, $logTable, $logUid, $targetLanguageId, $providerId, $result->model, count($items), count($segments), $result->characters, $result->inputTokens, $result->outputTokens, 'success', '');
        return count($items);
    }

    /**
     * Sammelt einen Datensatz und rekursiv seine Inline-Kinder (Standardsprache).
     *
     * @param array<string, mixed> $row
     * @param list<array{table: string, row: array<string, mixed>, fields: array<string, TranslatableField>, hint: string}> $items
     */
    private function collect(string $table, array $row, array &$items, int $depth = 0): void
    {
        $items[] = [
            'table' => $table,
            'row' => $row,
            'fields' => $this->fieldResolver->getTranslatableFields($table, $row),
            'hint' => $table === 'pages' ? 'Seiteneigenschaften' : $this->fieldResolver->getRecordTypeLabel($table, $row),
        ];
        if ($depth > 3) {
            return;
        }
        foreach ($this->fieldResolver->getChildRelations($table, $row) as $relation) {
            foreach ($this->findChildren($relation, (int)$row['uid']) as $child) {
                $this->collect($relation['table'], $child, $items, $depth + 1);
            }
        }
    }

    /**
     * @param array{field: string, table: string, foreignField: string, matchFields: array<string, string>} $relation
     * @return list<array<string, mixed>>
     */
    private function findChildren(array $relation, int $parentUid): array
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
            $languageField = $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName();
            $qb->andWhere($qb->expr()->eq($languageField, 0));
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, array<int, int>> $copyMapping
     */
    private function findTranslationUid(string $table, int $sourceUid, int $languageId, array $copyMapping): ?int
    {
        if (isset($copyMapping[$table][$sourceUid])) {
            return (int)$copyMapping[$table][$sourceUid];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->isLanguageAware()) {
            return null;
        }
        $capability = $schema->getCapability(TcaSchemaCapability::Language);
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

    private function findPageTranslation(int $pageUid, int $languageId): ?int
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
     * Erzeugt die URL einer neu angelegten Seitenübersetzung aus dem übersetzten Titel – unter der
     * übersetzten URL der übergeordneten Seite (z. B. /en/blog/coffee-house-culture/).
     * Direkt in der Datenbank, damit für die frisch angelegte Seite keine Weiterleitung entsteht.
     */
    private function regenerateSlug(int $translationUid): void
    {
        $row = BackendUtility::getRecord('pages', $translationUid);
        $config = $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? null;
        if ($row === null || !is_array($config)) {
            return;
        }
        $helper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $config);
        $slug = $helper->generate($row, (int)$row['pid']);
        $state = RecordStateFactory::forName('pages')->fromArray($row, (int)$row['pid'], $translationUid);
        $slug = $helper->buildSlugForUniqueInSite($slug, $state);
        if ($slug !== '' && $slug !== $row['slug']) {
            $this->connectionPool->getConnectionForTable('pages')->update('pages', ['slug' => $slug], ['uid' => $translationUid]);
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function log(array $page, string $table, int $uid, int $language, string $provider, string $model, int $records, int $segments, int $characters, int $inputTokens, int $outputTokens, string $status, string $message): void
    {
        $this->logRepository->add([
            'be_user' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            'page_uid' => (int)($page['uid'] ?? 0),
            'tablename' => $table,
            'record_uid' => $uid,
            'language' => $language,
            'provider' => $provider,
            'model' => $model,
            'records' => $records,
            'segments' => $segments,
            'characters' => $characters,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'status' => $status,
            'message' => $message,
        ]);
    }

    private function segmentId(int $item, string $field, string $suffix): string
    {
        return $item . ':' . $field . ($suffix !== '' ? ':' . $suffix : '');
    }
}
