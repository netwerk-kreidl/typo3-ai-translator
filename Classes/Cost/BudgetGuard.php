<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Cost;

use Netwerk\AiTranslator\Domain\Repository\LogRepository;

/**
 * Prüft vor jeder Übersetzung, ob das Monatslimit (Extension-Konfiguration „monthlyBudget“) reicht.
 */
final readonly class BudgetGuard
{
    public function __construct(
        private CostCalculator $calculator,
        private LogRepository $logRepository,
    ) {}

    /**
     * @throws BudgetExceededException
     */
    public function assertAvailable(string $provider, int $characters): void
    {
        $budget = $this->calculator->getMonthlyBudget();
        if ($budget <= 0) {
            return;
        }
        $spent = $this->logRepository->getMonthCost();
        $estimate = $this->calculator->estimate($provider, $characters);
        if ($spent + $estimate > $budget) {
            throw new BudgetExceededException(sprintf(
                'Monatslimit für KI-Übersetzungen erreicht: bisher %s € von %s €, diese Übersetzung ca. %s €. Das Limit lässt sich in der Extension-Konfiguration anpassen.',
                self::format($spent),
                self::format($budget),
                self::format($estimate),
            ), 1793130601);
        }
    }

    /**
     * @return array{budget: float, spent: float, remaining: float|null}
     */
    public function getStatus(): array
    {
        $budget = $this->calculator->getMonthlyBudget();
        $spent = round($this->logRepository->getMonthCost(), 2);
        return ['budget' => $budget, 'spent' => $spent, 'remaining' => $budget > 0 ? max(0.0, round($budget - $spent, 2)) : null];
    }

    public static function format(float $value): string
    {
        return number_format($value, $value > 0 && $value < 0.1 ? 3 : 2, ',', '.');
    }
}
