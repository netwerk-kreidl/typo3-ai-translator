<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Provider;

use GuzzleHttp\Psr7\Response;
use Netwerk\AiTranslator\Provider\RetryingHttpClient;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RetryingHttpClientTest extends UnitTestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    #[Test]
    public function retriesOnOverloadAndRespectsRetryAfter(): void
    {
        $client = $this->createClient([
            new Response(429, ['Retry-After' => '7']),
            new Response(503),
            new Response(200, [], 'ok'),
        ]);
        $response = $client->request('https://example.org', 'POST');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([7, 5], $this->sleeps);
    }

    #[Test]
    public function doesNotRetryClientErrors(): void
    {
        $client = $this->createClient([new Response(403), new Response(200)]);
        self::assertSame(403, $client->request('https://example.org', 'GET')->getStatusCode());
        self::assertSame([], $this->sleeps);
    }

    #[Test]
    public function returnsLastResponseAfterAllAttempts(): void
    {
        $client = $this->createClient([new Response(529), new Response(529), new Response(529), new Response(529), new Response(200)]);
        self::assertSame(529, $client->request('https://example.org', 'GET')->getStatusCode());
        self::assertSame([2, 5, 10], $this->sleeps);
    }

    #[Test]
    public function retriesConnectionErrorsAndRethrowsTheLastOne(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('timeout'));
        $client = new RetryingHttpClient($requestFactory);
        $client->setSleep(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
        $this->expectExceptionMessage('timeout');
        try {
            $client->request('https://example.org', 'GET');
        } finally {
            self::assertCount(3, $this->sleeps);
        }
    }

    /**
     * @param list<Response> $responses
     */
    private function createClient(array $responses): RetryingHttpClient
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(static function (string $url, string $method, array $options) use (&$responses): Response {
            self::assertFalse($options['http_errors']);
            return array_shift($responses);
        });
        $client = new RetryingHttpClient($requestFactory);
        $client->setSleep(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
        return $client;
    }
}
