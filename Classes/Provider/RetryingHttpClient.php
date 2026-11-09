<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * HTTP-Anfragen an die Anbieter mit automatischer Wiederholung bei Überlastung (429, 5xx) und
 * Verbindungsfehlern – mit Wartezeit (Retry-After des Anbieters oder 2, 5, 10 Sekunden).
 */
class RetryingHttpClient
{
    /** Statuscodes, bei denen sich ein erneuter Versuch lohnt (529 = überlastet) */
    public const RETRY_STATUS = [408, 429, 500, 502, 503, 504, 529];
    private const DELAYS = [2, 5, 10];
    private const MAX_DELAY = 30;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    public function __construct(private readonly RequestFactory $requestFactory)
    {
        $this->sleep = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Für Tests: Warten ersetzen.
     *
     * @param \Closure(int): void $sleep
     */
    public function setSleep(\Closure $sleep): void
    {
        $this->sleep = $sleep;
    }

    /**
     * @param array<string, mixed> $options Guzzle-Optionen (http_errors wird immer auf false gesetzt)
     * @throws \Throwable letzter Verbindungsfehler, wenn alle Versuche scheitern
     */
    public function request(string $url, string $method, array $options = []): ResponseInterface
    {
        $options['http_errors'] = false;
        $attempt = 0;
        while (true) {
            try {
                $response = $this->requestFactory->request($url, $method, $options);
                if (!in_array($response->getStatusCode(), self::RETRY_STATUS, true) || $attempt >= count(self::DELAYS)) {
                    return $response;
                }
                $delay = $this->retryAfter($response) ?? self::DELAYS[$attempt];
            } catch (\Throwable $e) {
                if ($attempt >= count(self::DELAYS)) {
                    throw $e;
                }
                $delay = self::DELAYS[$attempt];
            }
            ($this->sleep)(min($delay, self::MAX_DELAY));
            $attempt++;
        }
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return max(1, (int)$value);
        }
        $time = strtotime($value);
        return $time !== false ? max(1, $time - time()) : null;
    }
}
