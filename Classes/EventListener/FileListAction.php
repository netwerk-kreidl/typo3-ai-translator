<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\EventListener;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Status\FileStatusService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;

/**
 * Dateiliste: Aktion „Metadaten mit KI übersetzen“ – bei veralteter Übersetzung gut sichtbar als eigene Schaltfläche,
 * bei fehlender Übersetzung im Aktionsmenü.
 */
#[AsEventListener('ai-translator/file-list-action')]
final readonly class FileListAction
{
    public function __construct(
        private FileStatusService $fileStatus,
        private ProviderRegistry $providers,
        private ExtensionSettings $settings,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        $file = $event->getResource();
        if (!$file instanceof File || !$file->isIndexed() || !Permission::canTranslate() || !$file->checkActionPermission('editMeta')) {
            return;
        }
        $provider = array_key_first($this->providers->configured($this->settings->get('defaultProvider', 'deepl')));
        if ($provider === null) {
            return;
        }
        $outdated = [];
        $missing = [];
        foreach ($this->fileStatus->getLanguages() as $languageId => $language) {
            $status = $this->fileStatus->getStatus($file->getUid(), $languageId);
            if ($status['state'] === FileStatusService::STATE_OUTDATED) {
                $outdated[$languageId] = $language->getTitle();
            } elseif ($status['state'] === FileStatusService::STATE_MISSING) {
                $missing[$languageId] = $language->getTitle();
            }
        }
        if ($outdated === [] && $missing === []) {
            return;
        }
        $providerLabel = $this->providers->get($provider)->getLabel();
        $returnUrl = (string)$event->getRequest()->getUri();

        if ($outdated !== []) {
            $button = $this->componentFactory->createLinkButton()
                ->setHref($this->buildUrl($file, array_keys($outdated + $missing), $provider, $returnUrl))
                ->setTitle(sprintf('Übersetzung der Metadaten veraltet (%s) – mit %s aktualisieren', implode(', ', $outdated), $providerLabel))
                ->setIcon($this->iconFactory->getIcon('ai-translator-handler', IconSize::SMALL, 'overlay-warning'))
                ->setClasses('text-warning');
            $event->setAction($button, 'aiTranslatorUpdate', ActionGroup::primary, 'translations');
            return;
        }
        $button = $this->componentFactory->createLinkButton()
            ->setHref($this->buildUrl($file, array_keys($missing), $provider, $returnUrl))
            ->setTitle(sprintf('Metadaten mit %s übersetzen (%s)', $providerLabel, implode(', ', $missing)))
            ->setIcon($this->iconFactory->getIcon('ai-translator-handler', IconSize::SMALL));
        $event->setAction($button, 'aiTranslatorTranslate', ActionGroup::secondary);
    }

    /**
     * @param list<int> $languageIds
     */
    private function buildUrl(File $file, array $languageIds, string $provider, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('ai_translator_file', [
            'file' => $file->getUid(),
            'languages' => $languageIds,
            'provider' => $provider,
            'returnUrl' => $returnUrl,
        ]);
    }
}
