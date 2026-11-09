<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Provider;

use GuzzleHttp\Psr7\Response;
use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Provider\DeeplProvider;
use Netwerk\AiTranslator\Provider\LanguagePair;
use Netwerk\AiTranslator\Provider\RetryingHttpClient;
use Netwerk\AiTranslator\Provider\Segment;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Provider\TranslationRequest;
use Netwerk\AiTranslator\Provider\TranslationStyle;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeeplProviderTest extends UnitTestCase
{
    #[Test]
    public function glossaryIsBuiltAsTsv(): void
    {
        self::assertSame(
            "Zimmer\troom\nJunior Suite Joseph\tJunior Suite Joseph",
            DeeplProvider::buildGlossaryEntries(['Zimmer' => 'room', " Junior  Suite\tJoseph " => 'Junior Suite Joseph', 'leer' => ''])
        );
    }

    #[Test]
    public function translatesWithGlossaryAndProtectedTerms(): void
    {
        $calls = [];
        $http = $this->createStub(RetryingHttpClient::class);
        $http->method('request')->willReturnCallback(static function (string $url, string $method, array $options) use (&$calls): Response {
            $calls[] = [$url, $method, $options['json'] ?? []];
            if (str_ends_with($url, '/v2/glossaries')) {
                return new Response(201, [], (string)json_encode(['glossary_id' => 'g-1']));
            }
            return new Response(200, [], (string)json_encode(['translations' => [
                ['text' => 'Welcome to <span translate="no" data-ait="1">Josefshof</span><br>Vienna &amp; more'],
                ['text' => '<p>Rooms &amp; suites</p>'],
            ]]));
        });
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn([]);
        $registry->expects(self::once())->method('set')->with('ai_translator', self::anything(), self::callback(
            static fn(array $known): bool => array_values($known) === ['g-1']
        ));

        $result = $this->createProvider($http, $registry)->translate(new TranslationRequest(
            [
                'a' => new Segment("Willkommen im Josefshof\nWien & mehr"),
                'b' => new Segment('<p>Zimmer &amp; Suiten</p>', true),
            ],
            new LanguagePair('de', 'en', 'GB'),
            new TranslationStyle('formal', '', ['Josefshof'], ['Zimmer' => 'Room']),
        ));

        self::assertSame("Welcome to Josefshof\nVienna & more", $result->translations['a']);
        self::assertSame('<p>Rooms &amp; suites</p>', $result->translations['b']);
        self::assertCount(2, $calls);
        [$glossaryUrl, , $glossary] = $calls[0];
        self::assertSame('https://api-free.deepl.com/v2/glossaries', $glossaryUrl);
        self::assertSame(['de', 'en', "Zimmer\tRoom", 'tsv'], [$glossary['source_lang'], $glossary['target_lang'], $glossary['entries'], $glossary['entries_format']]);
        $translate = $calls[1][2];
        self::assertSame('g-1', $translate['glossary_id']);
        self::assertSame(['DE', 'EN-GB', 'prefer_more', 'html'], [$translate['source_lang'], $translate['target_lang'], $translate['formality'], $translate['tag_handling']]);
        self::assertStringContainsString('<span translate="no" data-ait="1">Josefshof</span>', $translate['text'][0]);
    }

    #[Test]
    public function knownGlossaryIsReused(): void
    {
        $calls = 0;
        $http = $this->createStub(RetryingHttpClient::class);
        $http->method('request')->willReturnCallback(static function (string $url, string $method, array $options) use (&$calls): Response {
            $calls++;
            self::assertStringEndsWith('/v2/translate', $url);
            self::assertSame('known', $options['json']['glossary_id']);
            return new Response(200, [], (string)json_encode(['translations' => [['text' => 'room']]]));
        });
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn([md5("Zimmer\troom") => 'known']);
        $registry->expects(self::never())->method('set');

        $this->createProvider($http, $registry)->translate(new TranslationRequest(
            ['a' => new Segment('Zimmer')],
            new LanguagePair('de', 'en'),
            new TranslationStyle('default', '', [], ['Zimmer' => 'room']),
        ));
        self::assertSame(1, $calls);
    }

    #[Test]
    public function quotaErrorIsReportedInGerman(): void
    {
        $http = $this->createStub(RetryingHttpClient::class);
        $http->method('request')->willReturn(new Response(456, [], '{}'));
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Zeichenkontingent');
        $this->createProvider($http, $this->createStub(Registry::class))->translate(new TranslationRequest(
            ['a' => new Segment('Hallo')],
            new LanguagePair('de', 'en'),
            new TranslationStyle(),
        ));
    }

    private function createProvider(RetryingHttpClient $http, Registry $registry): DeeplProvider
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['deeplApiKey' => 'secret:fx']);
        return new DeeplProvider(new ExtensionSettings($extensionConfiguration), $http, $registry, new NullLogger());
    }
}
