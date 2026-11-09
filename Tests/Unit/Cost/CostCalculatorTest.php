<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Cost;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Cost\BudgetGuard;
use Netwerk\AiTranslator\Cost\CostCalculator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CostCalculatorTest extends UnitTestCase
{
    #[Test]
    public function deeplCostIsBasedOnCharacters(): void
    {
        $calculator = $this->createCalculator(['priceDeepl' => '20']);
        self::assertEqualsWithDelta(0.2, $calculator->cost('deepl', 10000), 0.0001);
        self::assertEqualsWithDelta(0.2, $calculator->estimate('deepl', 10000), 0.0001);
    }

    #[Test]
    public function llmCostIsBasedOnTokensAndExchangeRate(): void
    {
        $calculator = $this->createCalculator(['priceAnthropicInput' => '4', 'priceAnthropicOutput' => '20', 'usdToEur' => '0,5']);
        // (1.000.000 × 4 $ + 100.000 × 20 $) / 1 Mio. = 6 $ → 3 €
        self::assertEqualsWithDelta(3.0, $calculator->cost('anthropic', 0, 1_000_000, 100_000), 0.0001);
    }

    #[Test]
    public function estimateForLanguageModelsIncludesOverhead(): void
    {
        $calculator = $this->createCalculator([]);
        self::assertGreaterThan(0.0, $calculator->estimate('openai', 1000));
        self::assertGreaterThan($calculator->estimate('openai', 1000), $calculator->estimate('openai', 50000));
        self::assertSame(0.0, $calculator->estimate('anthropic', 0));
    }

    #[Test]
    public function testProviderIsFree(): void
    {
        self::assertSame(0.0, $this->createCalculator([])->cost('pseudo', 100000, 1000, 1000));
    }

    #[Test]
    public function budgetDefaultsToUnlimited(): void
    {
        self::assertSame(0.0, $this->createCalculator([])->getMonthlyBudget());
        self::assertSame(25.5, $this->createCalculator(['monthlyBudget' => '25,50'])->getMonthlyBudget());
    }

    #[Test]
    public function amountsAreFormattedForGermanEditors(): void
    {
        self::assertSame('1.234,50', BudgetGuard::format(1234.5));
        self::assertSame('0,013', BudgetGuard::format(0.0125));
        self::assertSame('0,00', BudgetGuard::format(0.0));
    }

    /**
     * @param array<string, string> $configuration
     */
    private function createCalculator(array $configuration): CostCalculator
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);
        return new CostCalculator(new ExtensionSettings($extensionConfiguration));
    }
}
