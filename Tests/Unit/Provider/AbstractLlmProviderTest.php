<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Provider;

use Netwerk\AiTranslator\Provider\AbstractLlmProvider;
use Netwerk\AiTranslator\Provider\LanguagePair;
use Netwerk\AiTranslator\Provider\Segment;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Provider\TranslationRequest;
use Netwerk\AiTranslator\Provider\TranslationStyle;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AbstractLlmProviderTest extends UnitTestCase
{
    #[Test]
    public function promptContainsStyleGlossaryAndProtectedTerms(): void
    {
        $provider = $this->createProvider();
        $provider->translate(new TranslationRequest(
            ['1:header' => new Segment('Zimmer & Suiten', false, 'Überschrift', 60)],
            new LanguagePair('de', 'en', 'GB', 'Deutsch', 'English (GB)'),
            new TranslationStyle('formal', 'Herzlicher Ton', ['Josefshof'], ['Zimmer' => 'room']),
            'Website „Josefshof“',
        ));
        $system = $provider->calls[0]['system'];
        self::assertStringContainsString('from Deutsch into English (GB)', $system);
        self::assertStringContainsString('Address the reader formally', $system);
        self::assertStringContainsString('Tone and audience: Herzlicher Ton', $system);
        self::assertStringContainsString('Keep these terms unchanged: Josefshof', $system);
        self::assertStringContainsString('Always translate "Zimmer" as "room".', $system);
        $user = json_decode($provider->calls[0]['user'], true);
        self::assertSame(['id' => '1:header', 'text' => 'Zimmer & Suiten', 'format' => 'text', 'hint' => 'Überschrift', 'maxLength' => 60], $user['segments'][0]);
    }

    #[Test]
    public function manySegmentsAreSplitIntoSeveralRequests(): void
    {
        $segments = [];
        for ($i = 0; $i < 85; $i++) {
            $segments['s' . $i] = new Segment('Text ' . $i);
        }
        $provider = $this->createProvider();
        $result = $provider->translate(new TranslationRequest($segments, new LanguagePair('de', 'en'), new TranslationStyle()));
        self::assertCount(3, $provider->calls);
        self::assertCount(85, $result->translations);
        self::assertSame('EN: Text 84', $result->translations['s84']);
        self::assertSame(30, $result->inputTokens);
    }

    #[Test]
    public function missingTranslationsAreAnError(): void
    {
        $provider = $this->createProvider(dropLast: true);
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('1 Text(e) fehlen');
        $provider->translate(new TranslationRequest(
            ['a' => new Segment('Eins'), 'b' => new Segment('Zwei')],
            new LanguagePair('de', 'en'),
            new TranslationStyle(),
        ));
    }

    private function createProvider(bool $dropLast = false): AbstractLlmProvider
    {
        return new class ($dropLast) extends AbstractLlmProvider {
            /** @var list<array{system: string, user: string}> */
            public array $calls = [];

            public function __construct(private readonly bool $dropLast) {}

            public function getIdentifier(): string
            {
                return 'fake';
            }

            public function getLabel(): string
            {
                return 'Fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            protected function complete(string $system, string $user, array $schema): array
            {
                $this->calls[] = ['system' => $system, 'user' => $user];
                $translations = array_map(
                    static fn(array $segment): array => ['id' => $segment['id'], 'text' => 'EN: ' . $segment['text']],
                    json_decode($user, true)['segments']
                );
                if ($this->dropLast) {
                    array_pop($translations);
                }
                return ['text' => (string)json_encode(['translations' => $translations]), 'model' => 'fake-1', 'inputTokens' => 10, 'outputTokens' => 20];
            }
        };
    }
}
