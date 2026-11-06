<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Localization;

final class AnthropicLocalizationHandler extends AbstractAiLocalizationHandler
{
    protected function getProviderIdentifier(): string
    {
        return 'anthropic';
    }
}
