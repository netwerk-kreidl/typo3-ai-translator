<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Controller;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Domain\Repository\LogRepository;
use Netwerk\AiTranslator\Provider\LanguagePair;
use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Provider\Segment;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Provider\TranslationRequest;
use Netwerk\AiTranslator\Provider\TranslationStyle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Modul „KI-Übersetzung“: Status der Anbieter, Verbindungstest, Verbrauch und Protokoll.
 */
#[AsController]
final readonly class ModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ProviderRegistry $providers,
        private ExtensionSettings $settings,
        private LogRepository $logRepository,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('KI-Übersetzung');

        $providers = [];
        foreach ($this->providers->all() as $provider) {
            $id = $provider->getIdentifier();
            $providers[] = [
                'identifier' => $id,
                'label' => $provider->getLabel(),
                'configured' => $provider->isConfigured(),
                'fromEnvironment' => $this->settings->isFromEnvironment($id . 'ApiKey'),
                'isDefault' => $this->settings->get('defaultProvider', 'deepl') === $id,
                'model' => match ($id) {
                    'openai' => $this->settings->get('openaiModel'),
                    'anthropic' => $this->settings->get('anthropicModel') . ' · ' . $this->settings->get('anthropicEffort'),
                    default => '',
                },
            ];
        }

        $log = [];
        foreach ($this->logRepository->findLatest() as $entry) {
            $page = BackendUtility::getRecord('pages', (int)$entry['page_uid'], 'uid,title');
            $user = BackendUtility::getRecord('be_users', (int)$entry['be_user'], 'username,realName');
            $entry['pageTitle'] = $page['title'] ?? '';
            $entry['userName'] = ($user['realName'] ?? '') ?: ($user['username'] ?? '');
            $entry['pageLink'] = $page !== null
                ? (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $page['uid'], 'languages' => [(int)$entry['language']]])
                : '';
            $log[] = $entry;
        }

        $view->assignMultiple([
            'providers' => $providers,
            'totals' => $this->logRepository->getTotalsByProvider(strtotime('-30 days')),
            'log' => $log,
            'testUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator.test'),
            'isAdmin' => $GLOBALS['BE_USER']->isAdmin(),
        ]);
        return $view->renderResponse('Module/Index');
    }

    /**
     * Verbindungstest: übersetzt einen kurzen Satz ins Englische.
     */
    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        $providerId = (string)($request->getParsedBody()['provider'] ?? '');
        try {
            $provider = $this->providers->get($providerId);
            $result = $provider->translate(new TranslationRequest(
                ['test' => new Segment('Herzlich willkommen im Hotel – wir freuen uns auf Ihren Besuch!')],
                new LanguagePair('de', 'en', 'GB', 'Deutsch', 'English (GB)'),
                new TranslationStyle('formal'),
            ));
            $this->addFlashMessage(
                sprintf('%s funktioniert: „%s“', $provider->getLabel(), $result->translations['test'] ?? ''),
                ContextualFeedbackSeverity::OK
            );
        } catch (TranslationException $e) {
            $this->addFlashMessage($e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_translator'));
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, 'Verbindungstest', $severity, true)
        );
    }
}
