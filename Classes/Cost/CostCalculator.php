<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Cost;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;

/**
 * Kosten in Euro (Richtwerte aus der Extension-Konfiguration – die Preise der Anbieter ändern sich).
 *  - DeepL: Euro je 1 Mio. Zeichen
 *  - ChatGPT/Claude: US-Dollar je 1 Mio. Eingabe-/Ausgabe-Tokens, umgerechnet mit dem eingestellten Kurs
 */
final readonly class CostCalculator
{
    /** Grobe Schätzung: Zeichen je Token (Deutsch/Englisch, inkl. HTML) */
    private const CHARACTERS_PER_TOKEN = 3.2;
    /** Systemanweisung und JSON-Hülle je Anfrage an ein Sprachmodell (Tokens) */
    private const LLM_OVERHEAD_TOKENS = 900;
    /** So viele Zeichen gehen höchstens in eine Anfrage an ein Sprachmodell */
    private const LLM_CHARACTERS_PER_REQUEST = 12000;

    public function __construct(private ExtensionSettings $settings) {}

    public function cost(string $provider, int $characters, int $inputTokens = 0, int $outputTokens = 0): float
    {
        return match ($provider) {
            'deepl' => $characters / 1_000_000 * $this->price('priceDeepl', 20.0),
            'openai' => ($inputTokens * $this->price('priceOpenaiInput', 1.25) + $outputTokens * $this->price('priceOpenaiOutput', 10.0)) / 1_000_000 * $this->usdToEur(),
            'anthropic' => ($inputTokens * $this->price('priceAnthropicInput', 4.0) + $outputTokens * $this->price('priceAnthropicOutput', 20.0)) / 1_000_000 * $this->usdToEur(),
            default => 0.0,
        };
    }

    /**
     * Geschätzte Kosten für eine Anzahl zu übersetzender Zeichen (vor der Übersetzung).
     */
    public function estimate(string $provider, int $characters): float
    {
        if ($characters <= 0) {
            return 0.0;
        }
        if ($provider === 'deepl') {
            return $this->cost('deepl', $characters);
        }
        $requests = (int)ceil($characters / self::LLM_CHARACTERS_PER_REQUEST);
        $textTokens = (int)ceil($characters / self::CHARACTERS_PER_TOKEN);
        // Eingabe: Text + JSON + Anweisung; Ausgabe: Übersetzung (meist etwas länger) + JSON
        $input = (int)ceil($textTokens * 1.2) + $requests * self::LLM_OVERHEAD_TOKENS;
        $output = (int)ceil($textTokens * 1.35);
        // Claude denkt bei jeder Anfrage kurz nach (abhängig von „Sorgfalt“) – pauschaler Zuschlag
        if ($provider === 'anthropic') {
            $output += $requests * match ($this->settings->get('anthropicEffort', 'low')) {
                'high' => 2000,
                'medium' => 800,
                default => 200,
            };
        }
        return $this->cost($provider, $characters, $input, $output);
    }

    /**
     * Monatslimit in Euro (0 = kein Limit).
     */
    public function getMonthlyBudget(): float
    {
        return max(0.0, $this->price('monthlyBudget', 0.0));
    }

    private function usdToEur(): float
    {
        return $this->price('usdToEur', 0.9) ?: 0.9;
    }

    private function price(string $key, float $default): float
    {
        $value = str_replace(',', '.', $this->settings->get($key));
        return is_numeric($value) ? (float)$value : $default;
    }
}
