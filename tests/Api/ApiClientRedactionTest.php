<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * A monitor UUID in the path is a bearer credential for that monitor's ping
 * endpoint, and an exception message is the most copy-pasted string in
 * software — it lands in issue trackers, chat threads and aggregated logs.
 * So the transport failure reports the route it called, never the identifier
 * it called it with.
 */
final class ApiClientRedactionTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function test_transport_failure_names_the_route_not_the_monitor_uuid(): void
    {
        $client = $this->clientThatFailsWith('connection refused');

        try {
            $client->getMonitor(self::UUID);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringContainsString('/monitors/{uuid}', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase(self::UUID, $e->getMessage());
        }
    }

    public function test_transport_failure_redacts_a_uuid_quoted_by_the_underlying_client(): void
    {
        // Guzzle appends the failing request URI to its connection errors.
        $client = $this->clientThatFailsWith(
            'cURL error 7: Failed to connect for https://cronheart.com/api/v1/monitors/'.self::UUID,
        );

        try {
            $client->getMonitor(self::UUID);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringNotContainsStringIgnoringCase(self::UUID, $e->getMessage());
        }
    }

    public function test_transport_failure_log_carries_the_route_not_the_monitor_uuid(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientThatFailsWith('connection refused', $logger);

        try {
            $client->deleteMonitor(self::UUID);
        } catch (ApiTransportException) {
        }

        self::assertNotSame([], $logger->records);
        self::assertSame('/api/v1/monitors/{uuid}', $logger->records[0]['context']['route'] ?? null);
        self::assertStringNotContainsStringIgnoringCase(
            self::UUID,
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_transport_failure_redacts_a_numeric_channel_id(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientThatFailsWith('connection refused', $logger);

        try {
            $client->getChannel('90071992547409911');
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringContainsString('/channels/{id}', $e->getMessage());
            self::assertStringNotContainsString('90071992547409911', $e->getMessage());
            self::assertStringNotContainsString(
                '90071992547409911',
                json_encode($logger->records, \JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_the_rendered_exception_hides_a_uuid_the_cause_still_quotes(): void
    {
        // `getPrevious()` keeps the real transport exception so callers can
        // branch on its type — but PHP renders the whole chain, and that
        // rendering is what gets pasted somewhere public.
        $client = $this->clientThatFailsWith(
            'cURL error 7: Failed to connect for https://cronheart.com/api/v1/monitors/'.self::UUID,
        );

        try {
            $client->getMonitor(self::UUID);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertStringContainsString(self::UUID, (string) $e->getPrevious()->getMessage());
            self::assertStringNotContainsStringIgnoringCase(self::UUID, (string) $e);
        }
    }

    public function test_the_rendered_exception_hides_an_api_token(): void
    {
        $client = $this->clientThatFailsWith('rejected [Authorization: Bearer cmk_example_notarealtoken]');

        try {
            $client->getMonitor(self::UUID);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringNotContainsString('cmk_example_notarealtoken', $e->getMessage());
            self::assertStringNotContainsString('cmk_example_notarealtoken', (string) $e);
        }
    }

    private function clientThatFailsWith(string $message, ?InMemoryLogger $logger = null): MonitorApiClient
    {
        $error = new class($message) extends \RuntimeException implements ClientExceptionInterface {};
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', retries: 0, apiKey: 'cmk_test_token'),
            new RecordingHttpClient([$error]),
            $factory,
            $factory,
            $logger ?? new InMemoryLogger(),
        );
    }
}
