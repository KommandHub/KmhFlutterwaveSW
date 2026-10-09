<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Client\Http;

use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class FlutterwaveHttpClient.
 *
 * Test mode is not a different host: Flutterwave selects it from the credential
 * (`FLWSECK_TEST-…`), so there is a single base URL and `enableSandbox` only
 * swaps which key Config hands back.
 */
class FlutterwaveHttpClient implements HttpClientInterface
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Config $config,
        private readonly SymfonyHttpClientInterface $client,
    ) {
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @throws FlutterwaveException
     */
    public function get(string $endpoint, array $queryParams = [], ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('GET', $endpoint, ['query' => $queryParams], $salesChannelId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws FlutterwaveException
     */
    public function post(string $endpoint, array $payload = [], ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $salesChannelId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws FlutterwaveException
     */
    public function put(string $endpoint, array $payload = [], ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('PUT', $endpoint, ['json' => $payload], $salesChannelId);
    }

    /**
     * @throws FlutterwaveException
     */
    public function delete(string $endpoint, ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('DELETE', $endpoint, [], $salesChannelId);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws FlutterwaveException
     */
    private function request(string $method, string $endpoint, array $options, ?string $salesChannelId): ResponseInterface
    {
        $secretKey = $this->config->getSecretKey($salesChannelId);

        if ($secretKey === '') {
            throw new FlutterwaveException('Flutterwave secret key is not configured.');
        }

        // Without a timeout a stalled Flutterwave call holds the customer's
        // checkout (or the admin's refund) open for PHP's full execution time.
        $options['timeout'] = self::TIMEOUT_SECONDS;
        $options['headers'] = [
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ];

        try {
            return $this->client->request($method, self::BASE_URL . $endpoint, $options);
        } catch (\Throwable $e) {
            throw new FlutterwaveException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
