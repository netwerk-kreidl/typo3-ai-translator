<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;
use Netwerk\AiTranslator\Configuration\ExtensionSettings;

/**
 * Claude über das offizielle Anthropic-PHP-SDK.
 * Strukturierte Ausgabe (JSON-Schema), Sorgfalt per „effort“; bei einer Ablehnung aus
 * Sicherheitsgründen übernimmt serverseitig automatisch ein Ausweichmodell („fallbacks: default“).
 */
final class AnthropicProvider extends AbstractLlmProvider
{
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public function __construct(private readonly ExtensionSettings $settings) {}

    public function getIdentifier(): string
    {
        return 'anthropic';
    }

    public function getLabel(): string
    {
        return 'Claude (Anthropic)';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('anthropicApiKey') !== '';
    }

    protected function complete(string $system, string $user, array $schema): array
    {
        $model = $this->settings->get('anthropicModel', self::DEFAULT_MODEL) ?: self::DEFAULT_MODEL;
        $effort = $this->settings->get('anthropicEffort', 'low') ?: 'low';
        // Das SDK wiederholt 408/409/429/5xx und Verbindungsfehler selbst mit Wartezeit
        $client = new Client(apiKey: $this->settings->get('anthropicApiKey'), requestOptions: ['maxRetries' => 4]);
        try {
            $message = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                fallbacks: 'default',
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: $system,
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw new TranslationException('Claude: Der API-Key ist ungültig.', 1793130101);
        } catch (NotFoundException) {
            throw new TranslationException(sprintf('Claude: Das Modell „%s“ ist nicht verfügbar.', $model), 1793130102);
        } catch (RateLimitException) {
            throw new TranslationException('Claude: Zu viele Anfragen oder Guthaben aufgebraucht – bitte später erneut versuchen.', 1793130103);
        } catch (APIStatusException $e) {
            throw new TranslationException('Claude: ' . $e->getMessage(), 1793130104, $e);
        } catch (APIConnectionException $e) {
            throw new TranslationException('Claude: Keine Verbindung zur API.', 1793130105, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new TranslationException('Claude hat die Übersetzung abgelehnt.', 1793130106);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new TranslationException('Claude: Antwort zu lang – bitte weniger Inhalte auf einmal übersetzen.', 1793130107);
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return [
            'text' => $text,
            'model' => $message->model,
            'inputTokens' => (int)($message->usage->inputTokens ?? 0),
            'outputTokens' => (int)($message->usage->outputTokens ?? 0),
        ];
    }
}
