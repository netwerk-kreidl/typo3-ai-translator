<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Configuration;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsTest extends UnitTestCase
{
    #[Test]
    public function glossaryIsParsedFromLinesAndSemicolons(): void
    {
        self::assertSame(
            ['Zimmer' => 'room', 'Frühstück' => 'breakfast', 'Junior Suite' => 'Junior Suite'],
            ExtensionSettings::parseGlossary("Zimmer=room;Frühstück = breakfast\n\n# Kommentar\nJunior Suite=Junior Suite\nkein Eintrag")
        );
    }

    #[Test]
    public function languagePrefixLimitsEntryToTargetLanguage(): void
    {
        $text = "Zimmer=room\nen: Frühstück=breakfast\nfr: Frühstück=petit-déjeuner\nen-GB: Aufzug=lift";
        self::assertSame(['Zimmer' => 'room', 'Frühstück' => 'breakfast', 'Aufzug' => 'lift'], ExtensionSettings::parseGlossary($text, 'en'));
        self::assertSame(['Zimmer' => 'room', 'Frühstück' => 'petit-déjeuner'], ExtensionSettings::parseGlossary($text, 'fr'));
    }

    #[Test]
    public function colonInTermIsNotTreatedAsLanguagePrefix(): void
    {
        self::assertSame(['Check-in: 14 Uhr' => 'Check-in: 2 pm'], ExtensionSettings::parseGlossary('Check-in: 14 Uhr=Check-in: 2 pm', 'en'));
    }

    #[Test]
    public function styleWithoutSiteUsesGlobalConfiguration(): void
    {
        $settings = $this->createSettings([
            'formality' => 'informal',
            'styleHint' => 'Herzlich',
            'doNotTranslate' => 'Josefshof, Joe’s Bar',
            'glossary' => 'Zimmer=room;de: Zimmer=Raum',
        ]);
        $style = $settings->styleFor(null, 'en');
        self::assertSame('informal', $style->formality);
        self::assertSame('Herzlich', $style->styleHint);
        self::assertSame(['Josefshof', 'Joe’s Bar'], $style->doNotTranslate);
        self::assertSame(['Zimmer' => 'room'], $style->glossary);
    }

    #[Test]
    public function environmentVariableOverridesApiKey(): void
    {
        $settings = $this->createSettings(['deeplApiKey' => 'from-config']);
        putenv('AI_TRANSLATOR_DEEPL_API_KEY=from-env');
        try {
            self::assertSame('from-env', $settings->get('deeplApiKey'));
            self::assertTrue($settings->isFromEnvironment('deeplApiKey'));
        } finally {
            putenv('AI_TRANSLATOR_DEEPL_API_KEY');
        }
        self::assertSame('from-config', $settings->get('deeplApiKey'));
    }

    /**
     * @param array<string, string> $configuration
     */
    private function createSettings(array $configuration): ExtensionSettings
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);
        return new ExtensionSettings($extensionConfiguration);
    }
}
