<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ProviderRegistry
{
    /** @var array<string, TranslationProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<TranslationProviderInterface> $providers
     */
    public function __construct(#[AutowireIterator('ai_translator.provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getIdentifier()] = $provider;
        }
    }

    public function get(string $identifier): TranslationProviderInterface
    {
        return $this->providers[$identifier] ?? throw new TranslationException(sprintf('Unbekannter Anbieter „%s“.', $identifier), 1793130401);
    }

    public function has(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
    }

    /**
     * @return array<string, TranslationProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Eingerichtete Anbieter (mit API-Key), der Standard-Anbieter zuerst.
     *
     * @return array<string, TranslationProviderInterface>
     */
    public function configured(string $defaultIdentifier = ''): array
    {
        $result = array_filter($this->providers, static fn(TranslationProviderInterface $p) => $p->isConfigured());
        if (isset($result[$defaultIdentifier])) {
            $result = [$defaultIdentifier => $result[$defaultIdentifier]] + $result;
        }
        // Test-Anbieter nur anbieten, wenn kein echter eingerichtet ist
        if (count($result) > 1) {
            unset($result['pseudo']);
        }
        return $result;
    }
}
