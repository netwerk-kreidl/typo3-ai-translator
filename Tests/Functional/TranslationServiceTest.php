<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Functional;

use Netwerk\AiTranslator\Cost\BudgetExceededException;
use Netwerk\AiTranslator\Cost\BudgetGuard;
use Netwerk\AiTranslator\Queue\QueueProcessor;
use Netwerk\AiTranslator\Queue\QueueRepository;
use Netwerk\AiTranslator\Service\TranslationService;
use Netwerk\AiTranslator\Status\PageStatus;
use Netwerk\AiTranslator\Status\StatusService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Übersetzen, Erkennen veralteter Übersetzungen, Prüf-Status und Monatslimit mit dem Test-Anbieter
 * („[EN] “ vor jedem Text) gegen eine echte Datenbank.
 */
final class TranslationServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content', 'filelist', 'seo'];
    protected array $testExtensionsToLoad = [
        'netwerk/typo3-ai-translator',
        __DIR__ . '/Fixtures/Extensions/render_type_fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function missingPageTranslationIsCreatedWithAllContent(): void
    {
        $result = $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        self::assertTrue($result['createdPage']);

        $page = $this->findTranslation('pages', 'l10n_parent', 2);
        self::assertSame('[EN] Zimmer & Suiten', $page['title']);
        self::assertSame('[EN] Unsere Zimmer in der Josefstadt', $page['description']);
        // SEO-Titel mit eigener Darstellung (wie bei cs_seo) wird ebenfalls übersetzt
        self::assertSame('[EN] Zimmer & Suiten in Wien', $page['seo_title']);

        $content = $this->findTranslation('tt_content', 'l18n_parent', 10);
        self::assertSame('[EN] Unsere Zimmer', $content['header']);
        self::assertStringContainsString('title="[EN] Zur Startseite"', $content['bodytext']);
        self::assertStringStartsWith('<p>[EN] Mehr zur', $content['bodytext']);
        self::assertSame('[EN] Zweiter Text', $this->findTranslation('tt_content', 'l18n_parent', 11)['header']);
    }

    #[Test]
    public function technicalFieldsOfFileReferencesStayUntouched(): void
    {
        $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        $reference = $this->findTranslation('sys_file_reference', 'l10n_parent', 1);
        self::assertSame('tt_content', $reference['tablenames']);
        self::assertSame('image', $reference['fieldname']);
        self::assertSame('[EN] Bildtitel', $reference['title']);
        self::assertSame('[EN] Alternativtext am Element', $reference['alternative']);
    }

    #[Test]
    public function changedSourceTextIsDetectedAndOnlyThatFieldIsUpdated(): void
    {
        $service = $this->get(TranslationService::class);
        $service->updatePage(2, 1, 'pseudo');
        $translation = $this->findTranslation('tt_content', 'l18n_parent', 11);
        // Redakteur passt die Übersetzung des Texts an – das soll erhalten bleiben
        $this->saveAsEditor('tt_content', (int)$translation['uid'], ['bodytext' => '<p>Hello world (edited)</p>']);
        // Deutsche Überschrift ändert sich
        $this->saveAsEditor('tt_content', 11, ['header' => 'Zweiter Text, neu']);

        $status = $this->get(StatusService::class)->getPageStatus(2, 1, true);
        self::assertSame(PageStatus::STATE_OUTDATED, $status->getState());
        self::assertSame(['header'], array_keys($status->outdated[0]->fields));

        $service->updatePage(2, 1, 'pseudo');
        $translation = $this->findTranslation('tt_content', 'l18n_parent', 11);
        self::assertSame('[EN] Zweiter Text, neu', $translation['header']);
        self::assertSame('<p>Hello world (edited)</p>', $translation['bodytext']);
        self::assertSame(PageStatus::STATE_CURRENT, $this->get(StatusService::class)->getPageStatus(2, 1, true)->getState());
    }

    #[Test]
    public function ownImageInTranslationIsNotReportedAsMissing(): void
    {
        $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        $content = $this->findTranslation('tt_content', 'l18n_parent', 10);
        $reference = $this->findTranslation('sys_file_reference', 'l10n_parent', 1);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        // Redakteur entfernt das übernommene deutsche Bild und fügt eine eigene (englische) Grafik ein
        $connection->update('sys_file_reference', ['deleted' => 1], ['uid' => (int)$reference['uid']]);
        $connection->insert('sys_file_reference', [
            'pid' => 2, 'uid_local' => 1, 'uid_foreign' => (int)$content['uid'], 'tablenames' => 'tt_content',
            'fieldname' => 'image', 'sys_language_uid' => 1, 'l10n_parent' => 0, 'alternative' => 'English graphic',
        ]);

        $status = $this->get(StatusService::class)->getPageStatus(2, 1, true);
        self::assertSame(PageStatus::STATE_CURRENT, $status->getState());
        // Aktualisieren lässt das eigene Bild unangetastet
        $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $images = $qb->select('l10n_parent', 'alternative')->from('sys_file_reference')->where(
            $qb->expr()->eq('uid_foreign', (int)$content['uid']),
            $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
            $qb->expr()->eq('deleted', 0),
        )->executeQuery()->fetchAllAssociative();
        self::assertSame([['l10n_parent' => 0, 'alternative' => 'English graphic']], $images);
    }

    #[Test]
    public function aiTranslationsAreUnreviewedUntilAnEditorSavesThem(): void
    {
        $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        $status = $this->get(StatusService::class)->getPageStatus(2, 1, true);
        $translation = $this->findTranslation('tt_content', 'l18n_parent', 10);
        self::assertTrue($status->isUnreviewed('tt_content', (int)$translation['uid']));
        $before = $status->getUnreviewedCount();

        $this->saveAsEditor('tt_content', (int)$translation['uid'], ['header' => 'Our rooms']);

        $status = $this->get(StatusService::class)->getPageStatus(2, 1, true);
        self::assertFalse($status->isUnreviewed('tt_content', (int)$translation['uid']));
        self::assertSame($before - 1, $status->getUnreviewedCount());
    }

    #[Test]
    public function fileMetadataIsTranslatedAndUpdated(): void
    {
        $service = $this->get(TranslationService::class);
        self::assertTrue($service->updateFileMetadata(1, 1, 'pseudo')['created']);
        self::assertSame('[EN] Fassade des Hotels', $this->findTranslation('sys_file_metadata', 'l10n_parent', 1)['alternative']);

        $this->saveAsEditor('sys_file_metadata', 1, ['alternative' => 'Jugendstil-Fassade']);
        self::assertSame(1, $service->updateFileMetadata(1, 1, 'pseudo')['fields']);
        self::assertSame('[EN] Jugendstil-Fassade', $this->findTranslation('sys_file_metadata', 'l10n_parent', 1)['alternative']);
    }

    #[Test]
    public function queueStopsWhenTheMonthlyBudgetIsReached(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_translator'] = ['monthlyBudget' => '1', 'deeplApiKey' => 'test:fx', 'priceDeepl' => '20'];
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_aitranslator_log')
            ->insert('tx_aitranslator_log', ['crdate' => time(), 'provider' => 'deepl', 'status' => 'success', 'cost' => 0.99999, 'message' => '']);

        $queue = $this->get(QueueRepository::class);
        $queue->add([2], 1, 'deepl', 1);
        $result = $this->get(QueueProcessor::class)->processNext();

        self::assertSame(QueueProcessor::STATUS_BUDGET, $result['status']);
        self::assertStringContainsString('Monatslimit', $result['message']);
        self::assertSame(1, $queue->getCounts()[QueueRepository::PENDING]);
        self::assertNull($this->findTranslation('pages', 'l10n_parent', 2, false));
    }

    #[Test]
    public function budgetGuardAllowsTranslationsWithinTheLimit(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_translator'] = ['monthlyBudget' => '1', 'priceDeepl' => '20'];
        $guard = $this->get(TranslationService::class);
        $budget = (new \ReflectionProperty($guard, 'budgetGuard'))->getValue($guard);
        self::assertInstanceOf(BudgetGuard::class, $budget);
        $budget->assertAvailable('deepl', 40000);
        $this->expectException(BudgetExceededException::class);
        $budget->assertAvailable('deepl', 60000);
    }

    #[Test]
    public function estimateCountsVisibleCharactersOfMissingTranslations(): void
    {
        $characters = $this->get(TranslationService::class)->estimatePage(2, 1);
        // Seitentitel, Beschreibung, Überschriften, Texte (ohne HTML), Linktitel und Bildtexte
        self::assertGreaterThan(80, $characters);
        self::assertLessThan(200, $characters);
        $this->get(TranslationService::class)->updatePage(2, 1, 'pseudo');
        self::assertSame(0, $this->get(TranslationService::class)->estimatePage(2, 1));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveAsEditor(string $table, int $uid, array $data): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $data]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTranslation(string $table, string $parentField, int $parentUid, bool $required = true): ?array
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from($table)->where(
            $qb->expr()->eq($parentField, $parentUid),
            $qb->expr()->eq('sys_language_uid', 1),
        )->executeQuery()->fetchAssociative() ?: null;
        if ($required) {
            self::assertIsArray($row, sprintf('Keine Übersetzung von %s:%d gefunden', $table, $parentUid));
        }
        return $row;
    }

    private function writeSiteConfiguration(): void
    {
        $path = Environment::getConfigPath() . '/sites/test';
        GeneralUtility::mkdir_deep($path);
        file_put_contents($path . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost/'
            websiteTitle: 'Testhotel'
            languages:
              - languageId: 0
                title: Deutsch
                locale: de_AT.UTF-8
                base: /
                flag: at
              - languageId: 1
                title: English
                locale: en_GB.UTF-8
                base: /en/
                flag: gb
                fallbackType: strict
            YAML);
    }
}
