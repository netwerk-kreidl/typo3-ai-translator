<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Localization;

final class DeeplLocalizationHandler extends AbstractAiLocalizationHandler
{
    protected function getProviderIdentifier(): string
    {
        return 'deepl';
    }
}
