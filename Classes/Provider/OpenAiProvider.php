<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;

/**
 * ChatGPT über die OpenAI Chat-Completions-API mit strukturierter Ausgabe (JSON-Schema).
 */
final class OpenAiProvider extends AbstractLlmProvider
{
    public const DEFAULT_MODEL = 'gpt-5';
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly RetryingHttpClient $httpClient,
    ) {}

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function getLabel(): string
    {
        return 'ChatGPT (OpenAI)';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('openaiApiKey') !== '';
    }

    protected function complete(string $system, string $user, array $schema): array
    {
        $model = $this->settings->get('openaiModel', self::DEFAULT_MODEL) ?: self::DEFAULT_MODEL;
        try {
            $response = $this->httpClient->request(self::ENDPOINT, 'POST', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->settings->get('openaiApiKey'),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'translations', 'strict' => true, 'schema' => $schema],
                    ],
                ],
                'timeout' => 300,
            ]);
        } catch (\Throwable $e) {
            throw new TranslationException('ChatGPT: Keine Verbindung zur API.', 1793130201, $e);
        }
        $data = json_decode((string)$response->getBody(), true) ?: [];
        $status = $response->getStatusCode();
        if ($status !== 200) {
            $error = (string)($data['error']['message'] ?? $response->getReasonPhrase());
            throw new TranslationException(match ($status) {
                401 => 'ChatGPT: Der API-Key ist ungültig.',
                404 => sprintf('ChatGPT: Das Modell „%s“ ist nicht verfügbar.', $model),
                429 => 'ChatGPT: Zu viele Anfragen oder Guthaben aufgebraucht – bitte später erneut versuchen.',
                default => 'ChatGPT: ' . $error,
            }, 1793130202);
        }
        $choice = $data['choices'][0] ?? [];
        if (!empty($choice['message']['refusal'])) {
            throw new TranslationException('ChatGPT hat die Übersetzung abgelehnt.', 1793130203);
        }
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new TranslationException('ChatGPT: Antwort zu lang – bitte weniger Inhalte auf einmal übersetzen.', 1793130204);
        }
        return [
            'text' => (string)($choice['message']['content'] ?? ''),
            'model' => (string)($data['model'] ?? $model),
            'inputTokens' => (int)($data['usage']['prompt_tokens'] ?? 0),
            'outputTokens' => (int)($data['usage']['completion_tokens'] ?? 0),
        ];
    }
}
