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
use Netwerk\AiTranslator\Status\StatusService;
use TYPO3\CMS\Backend\Localization\LocalizationMode;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
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
        private readonly RecordFinder $finder,
        private readonly StatusService $statusService,
        private readonly ConnectionPool $connectionPool,
        private readonly LogRepository $logRepository,
        private readonly StateRepository $stateRepository,
    ) {}

    /**
     * Legt die Seitenübersetzung an (falls nötig) und übersetzt die gewählten Inhaltselemente.
     *
     * @param list<int> $contentUids Inhaltselemente (tt_content), die mitübersetzt werden
     * @return array{createdPage: bool, records: int}
     * @throws TranslationException
     */
    public function translatePage(int $pageUid, int $targetLanguageId, array $contentUids, string $providerId, LocalizationMode $mode): array
    {
        return $this->translatePageRecords($pageUid, $targetLanguageId, ['tt_content' => $contentUids], $providerId, $mode);
    }

    /**
     * Bringt die Übersetzung einer Seite auf den aktuellen Stand: legt sie samt allen Inhalten an, falls sie fehlt,
     * übersetzt sonst nur geänderte Felder neu und ergänzt neue Unterelemente und noch nicht übersetzte Inhalte.
     * Die URL (Slug) bestehender Übersetzungen bleibt unverändert.
     *
     * @return array{createdPage: bool, records: int, fields: int}
     * @throws TranslationException
     */
    public function updatePage(int $pageUid, int $targetLanguageId, string $providerId): array
    {
        $status = $this->statusService->getPageStatus($pageUid, $targetLanguageId, true);
        $page = BackendUtility::getRecord('pages', $pageUid);
        if ($page === null) {
            throw new TranslationException(sprintf('Seite %d nicht gefunden.', $pageUid), 1793130506);
        }
        $freeMode = $this->finder->usesFreeMode($pageUid, $targetLanguageId);
        $missing = [];
        foreach ($status->missing as $record) {
            $missing[$record['table']][] = $record['uid'];
        }
        if (!$status->isFolder && $status->pageTranslationUid === null) {
            $result = $this->translatePageRecords($pageUid, $targetLanguageId, $missing, $providerId, LocalizationMode::TRANSLATE);
            return $result + ['fields' => 0];
        }

        $items = [];
        $commands = [];
        $fields = 0;
        foreach ($status->outdated as $outdated) {
            $row = BackendUtility::getRecord($outdated->table, $outdated->sourceUid);
            if ($row === null) {
                continue;
            }
            if ($outdated->fields !== []) {
                $all = $this->fieldResolver->getTranslatableFields($outdated->table, $row);
                $items[] = [
                    'table' => $outdated->table,
                    'row' => $row,
                    'fields' => array_intersect_key($all, $outdated->fields),
                    'hint' => $this->hint($outdated->table, $row),
                    'translationUid' => $outdated->translationUid,
                ];
                $fields += count($outdated->fields);
            }
            foreach ($outdated->newChildren as $field => $children) {
                $commands[$outdated->table][$outdated->translationUid]['inlineLocalizeSynchronize'] = [
                    'field' => $field,
                    'language' => $targetLanguageId,
                    'ids' => $children['uids'],
                ];
                foreach ($children['uids'] as $childUid) {
                    $child = BackendUtility::getRecord($children['table'], $childUid);
                    if ($child !== null) {
                        $this->collect($children['table'], $child, $items);
                    }
                }
            }
        }
        foreach ($missing as $table => $uids) {
            foreach ($uids as $uid) {
                $row = BackendUtility::getRecord($table, $uid);
                if ($row === null) {
                    continue;
                }
                $this->collect($table, $row, $items);
                $command = $table === 'tt_content' && $freeMode ? 'copyToLanguage' : 'localize';
                $commands[$table][$uid] = [$command => $targetLanguageId];
            }
        }
        if ($items === [] && $commands === []) {
            return ['createdPage' => false, 'records' => 0, 'fields' => 0];
        }
        $site = $this->siteFinder->getSiteByPageId($pageUid);
        $records = $this->run($items, $commands, $site, $page, $targetLanguageId, $providerId, 'pages', $pageUid, 'Aktualisierung');
        return ['createdPage' => false, 'records' => $records, 'fields' => $fields];
    }

    /**
     * @param array<string, list<int>> $recordUids Tabelle => UIDs der Ausgangsdatensätze auf der Seite
     * @return array{createdPage: bool, records: int}
     * @throws TranslationException
     */
    private function translatePageRecords(int $pageUid, int $targetLanguageId, array $recordUids, string $providerId, LocalizationMode $mode): array
    {
        $page = BackendUtility::getRecord('pages', $pageUid);
        if ($page === null) {
            throw new TranslationException(sprintf('Seite %d nicht gefunden.', $pageUid), 1793130501);
        }
        $site = $this->siteFinder->getSiteByPageId($pageUid);
        $isFolder = (int)$page['doktype'] === RecordFinder::DOKTYPE_FOLDER;
        $createPage = !$isFolder && $this->finder->findPageTranslation($pageUid, $targetLanguageId) === null;

        $items = [];
        $commands = [];
        if ($createPage) {
            $this->collect('pages', $page, $items);
            // Seiten werden immer als verbundene Übersetzung angelegt (wie im TYPO3-Standard)
            $commands['pages'][$pageUid] = ['localize' => $targetLanguageId];
        }
        foreach ($recordUids as $table => $uids) {
            foreach ($uids as $uid) {
                $row = BackendUtility::getRecord($table, (int)$uid);
                if ($row === null) {
                    continue;
                }
                $this->collect($table, $row, $items);
                $command = $table === 'tt_content' ? $mode->getDataHandlerCommand() : 'localize';
                $commands[$table][(int)$uid] = [$command => $targetLanguageId];
            }
        }
        if ($commands === []) {
            return ['createdPage' => false, 'records' => 0];
        }
        $records = $this->run($items, $commands, $site, $page, $targetLanguageId, $providerId, 'pages', $pageUid);
        return ['createdPage' => $createPage, 'records' => $records];
    }

    /**
     * Übersetzt die Metadaten einer Datei (Titel, Alternativtext, Beschreibung) bzw. bringt eine bestehende
     * Übersetzung auf den aktuellen Stand (nur geänderte Felder).
     *
     * @return array{created: bool, fields: int}
     * @throws TranslationException
     */
    public function updateFileMetadata(int $fileUid, int $targetLanguageId, string $providerId): array
    {
        $metadata = $this->finder->findFileMetadata($fileUid);
        if ($metadata === null || $this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata) === []
            || !$this->hasText($metadata, $this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata))) {
            return ['created' => false, 'fields' => 0];
        }
        $translationUid = $this->finder->findTranslationUid('sys_file_metadata', (int)$metadata['uid'], $targetLanguageId);
        if ($translationUid === null) {
            $this->translateRecord('sys_file_metadata', (int)$metadata['uid'], $targetLanguageId, $providerId, LocalizationMode::TRANSLATE);
            return ['created' => true, 'fields' => count($this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata))];
        }
        $translation = BackendUtility::getRecord('sys_file_metadata', $translationUid);
        $changed = $translation !== null ? $this->statusService->getChangedFields('sys_file_metadata', $metadata, $translation) : [];
        if ($changed === []) {
            return ['created' => false, 'fields' => 0];
        }
        $items = [[
            'table' => 'sys_file_metadata',
            'row' => $metadata,
            'fields' => array_intersect_key($this->fieldResolver->getTranslatableFields('sys_file_metadata', $metadata), $changed),
            'hint' => $this->hint('sys_file_metadata', $metadata),
            'translationUid' => $translationUid,
        ]];
        $page = ['uid' => 0, 'title' => $this->getFileName($fileUid)];
        $this->run($items, [], $this->resolveSite(0, $targetLanguageId), $page, $targetLanguageId, $providerId, 'sys_file_metadata', (int)$metadata['uid'], 'Aktualisierung');
        return ['created' => false, 'fields' => count($changed)];
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
        $page = ($pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid) : null) ?? ['uid' => $pageUid, 'title' => ''];
        $site = $this->resolveSite($pageUid, $targetLanguageId);
        if ($table === 'sys_file_metadata') {
            $page = ['uid' => 0, 'title' => $this->getFileName((int)$row['file'])];
        }
        $items = [];
        $this->collect($table, $row, $items);
        $commands = [$table => [$uid => [$mode->getDataHandlerCommand() => $targetLanguageId]]];
        $this->run($items, $commands, $site, $page, $targetLanguageId, $providerId, $table, $uid);
        return $this->finder->findTranslationUid($table, $uid, $targetLanguageId) ?? 0;
    }

    /**
     * @param list<array{table: string, row: array<string, mixed>, fields: array<string, TranslatableField>, hint: string, translationUid?: int}> $items
     *        translationUid: bestehende Übersetzung, deren Felder überschrieben werden (sonst wird sie per Befehl angelegt)
     * @param array<string, array<int, array<string, mixed>>> $commands
     * @param array<string, mixed> $page
     */
    private function run(array $items, array $commands, Site $site, array $page, int $targetLanguageId, string $providerId, string $logTable, int $logUid, string $logMessage = ''): int
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
                (int)($page['uid'] ?? 0) > 0
                    ? sprintf('Website „%s“, Seite „%s“', $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(), (string)($page['title'] ?? ''))
                    : sprintf('Website „%s“, %s', $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(), (string)($page['title'] ?? '')),
            );
            try {
                $result = $provider->translate($request);
            } catch (TranslationException $e) {
                $this->log($page, $logTable, $logUid, $targetLanguageId, $providerId, '', count($items), count($segments), 0, 0, 0, 'error', trim($logMessage . ': ' . $e->getMessage(), ': '));
                throw $e;
            }
        }

        // 3. Übersetzungen anlegen
        $copyMapping = [];
        if ($commands !== []) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $commands);
            $dataHandler->process_cmdmap();
            if ($dataHandler->errorLog !== []) {
                throw new TranslationException(implode("\n", $dataHandler->errorLog), 1793130504);
            }
            $copyMapping = $dataHandler->copyMappingArray_merged;

            // Bilder/Unterelemente der Seiteneigenschaften übernimmt TYPO3 beim Anlegen der Seitenübersetzung nicht –
            // daher nachträglich lokalisieren (z. B. Vorschaubild einer Zimmerseite)
            $pageCommands = [];
            foreach ($commands['pages'] ?? [] as $pageUid => $pageCommand) {
                if (!isset($pageCommand['localize'])) {
                    continue;
                }
                $pageRow = BackendUtility::getRecord('pages', (int)$pageUid);
                foreach ($pageRow !== null ? $this->fieldResolver->getChildRelations('pages', $pageRow) : [] as $relation) {
                    if ($this->finder->findChildren($relation, (int)$pageUid) !== []) {
                        $pageCommands[] = ['pages' => [(int)$pageUid => ['inlineLocalizeSynchronize' => [
                            'field' => $relation['field'],
                            'language' => $targetLanguageId,
                            'action' => 'localize',
                        ]]]];
                    }
                }
            }
            foreach ($pageCommands as $pageCommand) {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], $pageCommand);
                $dataHandler->process_cmdmap();
            }
        }

        // 4. Texte eintragen
        $datamap = [];
        $state = [];
        $newPageTranslationUid = null;
        foreach ($items as $i => $item) {
            $table = $item['table'];
            $sourceUid = (int)$item['row']['uid'];
            $isExisting = isset($item['translationUid']);
            $translationUid = $item['translationUid'] ?? $this->finder->findTranslationUid($table, $sourceUid, $targetLanguageId, $copyMapping);
            if ($translationUid === null) {
                continue;
            }
            if ($table === 'pages' && !$isExisting) {
                $newPageTranslationUid = $translationUid;
            }
            if ($isExisting) {
                // Im Ausgangstext geleerte Felder auch in der Übersetzung leeren
                foreach ($item['fields'] as $fieldName => $field) {
                    if (!isset($templates[$i][$fieldName]) && trim((string)($item['row'][$fieldName] ?? '')) === '') {
                        $datamap[$table][$translationUid][$fieldName] = '';
                        $state[] = [$table, $translationUid, $sourceUid, $fieldName, '', ''];
                    }
                }
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
        $this->log($page, $logTable, $logUid, $targetLanguageId, $providerId, $result->model, count($items), count($segments), $result->characters, $result->inputTokens, $result->outputTokens, 'success', $logMessage);
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
            'hint' => $this->hint($table, $row),
        ];
        if ($depth > 3) {
            return;
        }
        foreach ($this->fieldResolver->getChildRelations($table, $row) as $relation) {
            foreach ($this->finder->findChildren($relation, (int)$row['uid']) as $child) {
                $this->collect($relation['table'], $child, $items, $depth + 1);
            }
        }
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

    /**
     * Site für den Sprachkontext: über die Seite, bei Dateien (ohne Seite) die erste Site mit dieser Sprache.
     */
    private function resolveSite(int $pageUid, int $languageId): Site
    {
        if ($pageUid > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($pageUid);
            } catch (SiteNotFoundException) {
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getLanguages() as $language) {
                if ($language->getLanguageId() === $languageId) {
                    return $site;
                }
            }
        }
        throw new TranslationException(sprintf('Keine Website mit der Sprache %d gefunden.', $languageId), 1793130507);
    }

    /**
     * Kontext für die KI: Art des Datensatzes (bei Dateien inkl. Dateiname).
     *
     * @param array<string, mixed> $row
     */
    private function hint(string $table, array $row): string
    {
        return match ($table) {
            'pages' => 'Seiteneigenschaften',
            'sys_file_metadata' => sprintf('Metadaten der Datei „%s“ (Alternativtext = Bildbeschreibung für Screenreader)', $this->getFileName((int)($row['file'] ?? 0))),
            'sys_file_reference' => 'Bild/Datei-Verwendung (Alternativtext = Bildbeschreibung für Screenreader)',
            default => $this->fieldResolver->getRecordTypeLabel($table, $row),
        };
    }

    private function getFileName(int $fileUid): string
    {
        return 'Datei ' . (string)(BackendUtility::getRecord('sys_file', $fileUid, 'name')['name'] ?? $fileUid);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, TranslatableField> $fields
     */
    private function hasText(array $row, array $fields): bool
    {
        foreach ($fields as $name => $field) {
            if (trim(strip_tags((string)($row[$name] ?? ''))) !== '') {
                return true;
            }
        }
        return false;
    }

    private function segmentId(int $item, string $field, string $suffix): string
    {
        return $item . ':' . $field . ($suffix !== '' ? ':' . $suffix : '');
    }
}
