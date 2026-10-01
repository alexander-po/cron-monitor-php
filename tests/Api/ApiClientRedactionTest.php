<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use CronMonitor\Tests\Support\SecretTraceAssertions;
use CronMonitor\Tests\Support\UuidPlaceholder;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A monitor UUID in the path is a bearer credential for that monitor's ping
 * endpoint, and an exception message is the most copy-pasted string in
 * software — it lands in issue trackers, chat threads and aggregated logs.
 * So the transport failure reports the route it called, never the identifier
 * it called it with.
 */
final class ApiClientRedactionTest extends TestCase
{
    use SecretTraceAssertions;

    private const UUID = '00000000-0000-0000-0000-000000000001';

    private const ROTATED_UUID = '00000000-0000-0000-0000-000000000002';

    private const API_KEY = 'cmk_fake_redaction_test_key';

    public function test_transport_failure_names_the_route_not_the_monitor_uuid(): void
    {
        $client = $this->clientThatFailsWith('connection refused');

        try {
            $client->getMonitor(self::uuid());
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringContainsString('/monitors/{uuid}', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase(self::uuid(), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uuidSpellings(): iterable
    {
        yield 'lower case' => [self::uuid()];
        yield 'upper case' => [strtoupper(self::uuid())];
    }

    #[DataProvider('uuidSpellings')]
    public function test_transport_failure_redacts_a_uuid_quoted_by_the_underlying_client(string $uuid): void
    {
        // Guzzle appends the failing request URI to its connection errors.
        $client = $this->clientThatFailsWith(
            'cURL error 7: Failed to connect for https://cronheart.com/api/v1/monitors/'.$uuid,
        );

        try {
            $client->getMonitor($uuid);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringNotContainsStringIgnoringCase($uuid, $e->getMessage());
        }
    }

    #[DataProvider('uuidSpellings')]
    public function test_transport_failure_log_carries_the_route_not_the_monitor_uuid(string $uuid): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientThatFailsWith('connection refused', $logger);

        try {
            $client->deleteMonitor($uuid);
        } catch (ApiTransportException) {
        }

        self::assertNotSame([], $logger->records);
        self::assertSame('/api/v1/monitors/{uuid}', $logger->records[0]['context']['route'] ?? null);
        self::assertStringNotContainsStringIgnoringCase(
            $uuid,
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_uuid_with_a_trailing_newline_is_rejected_before_the_route_names_it(): void
    {
        $client = $this->clientThatFailsWith('connection refused');

        try {
            $client->getMonitor(self::uuid()."\n");
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException|ApiTransportException $e) {
            self::assertStringNotContainsStringIgnoringCase(self::uuid(), $e->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    public function test_a_uuid_with_a_trailing_newline_leaves_no_route_in_the_log(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientThatFailsWith('connection refused', $logger);

        try {
            $client->deleteMonitor(self::uuid()."\n");
        } catch (\InvalidArgumentException|ApiTransportException) {
        }

        self::assertStringNotContainsStringIgnoringCase(
            self::uuid(),
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
        self::assertSame([], $logger->records);
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
            'cURL error 7: Failed to connect for https://cronheart.com/api/v1/monitors/'.self::uuid(),
        );

        try {
            $client->getMonitor(self::uuid());
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertStringContainsString(self::uuid(), (string) $e->getPrevious()->getMessage());
            self::assertStringNotContainsStringIgnoringCase(self::uuid(), (string) $e);
        }
    }

    public function test_the_rendered_exception_hides_an_api_token(): void
    {
        $client = $this->clientThatFailsWith('rejected [Authorization: Bearer cmk_example_notarealtoken]');

        try {
            $client->getMonitor(self::uuid());
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertStringNotContainsString('cmk_example_notarealtoken', $e->getMessage());
            self::assertStringNotContainsString('cmk_example_notarealtoken', (string) $e);
        }
    }

    public function test_a_failed_monitor_call_keeps_the_uuid_out_of_trace_arguments(): void
    {
        $calls = [
            static fn (MonitorApiClient $c): mixed => $c->getMonitor(self::uuid()),
            static fn (MonitorApiClient $c): mixed => $c->updateMonitor(self::uuid(), new UpdateMonitorRequest(name: 'Renamed')),
            static function (MonitorApiClient $c): mixed {
                $c->deleteMonitor(self::uuid());

                return null;
            },
            static fn (MonitorApiClient $c): mixed => $c->pauseMonitor(self::uuid()),
            static fn (MonitorApiClient $c): mixed => $c->resumeMonitor(self::uuid()),
            static fn (MonitorApiClient $c): mixed => $c->snoozeMonitor(self::uuid(), SnoozeDuration::OneHour),
            static fn (MonitorApiClient $c): mixed => $c->unsnoozeMonitor(self::uuid()),
            static fn (MonitorApiClient $c): mixed => $c->rotateMonitorUuid(self::uuid()),
            static fn (MonitorApiClient $c): mixed => $c->listPings(self::uuid()),
            static fn (MonitorApiClient $c): mixed => iterator_to_array($c->allPings(self::uuid())),
            static fn (MonitorApiClient $c): mixed => $c->listAlerts(self::uuid()),
            static fn (MonitorApiClient $c): mixed => iterator_to_array($c->allAlerts(self::uuid())),
        ];

        foreach ($calls as $call) {
            foreach ([self::uuid(), self::API_KEY] as $secret) {
                $client = $this->clientThatAnswers(new Response(404, ['Content-Type' => 'application/problem+json'], '{"title":"Not Found","detail":"No such monitor."}'));

                $this->assertSecretStaysOutOfTraces($secret, static fn () => $call($client), NotFoundException::class);
            }
        }
    }

    public function test_a_rejected_uuid_is_neither_quoted_nor_left_in_trace_arguments(): void
    {
        $client = $this->clientThatAnswers();

        foreach ([self::uuid(), self::API_KEY] as $secret) {
            $this->assertSecretStaysOutOfTraces($secret, static fn () => $client->getMonitor(self::uuid().' '), \InvalidArgumentException::class);
        }
    }

    public function test_a_refused_connection_keeps_the_bearer_token_and_the_uuid_out_of_trace_arguments(): void
    {
        $client = MonitorApiClient::create(new Configuration('https://127.0.0.1:1', timeoutSeconds: 1.0, retries: 0, apiKey: self::API_KEY));
        $call = static fn () => $client->getMonitor(self::uuid());

        $printed = self::printedWithoutFrameArguments($call, ApiTransportException::class);
        foreach ([self::API_KEY, self::uuid()] as $secret) {
            $this->assertSecretStaysOutOfTraces($secret, $call, ApiTransportException::class);

            self::assertStringNotContainsString($secret, $printed);
        }
    }

    public function test_a_patch_that_cannot_be_encoded_keeps_the_uuid_out_of_trace_arguments(): void
    {
        $client = $this->clientThatAnswers();

        foreach ([self::uuid(), self::API_KEY] as $secret) {
            $this->assertSecretStaysOutOfTraces($secret, static fn () => $client->updateMonitor(self::uuid(), new UpdateMonitorRequest(name: "Nightly\xB1report")), ApiTransportException::class);
        }
    }

    public function test_an_unreadable_rotation_keeps_the_old_and_the_new_uuid_out_of_trace_arguments(): void
    {
        $rotated = [
            'uuid' => self::ROTATED_UUID,
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 'sixty',
            'ping_url' => 'https://cronheart.com/ping/'.self::ROTATED_UUID,
        ];

        foreach ([self::uuid(), self::ROTATED_UUID, self::API_KEY] as $secret) {
            $client = $this->clientThatAnswers(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($rotated)));

            $this->assertSecretStaysOutOfTraces($secret, static fn () => $client->rotateMonitorUuid(self::uuid()), ApiTransportException::class);
        }
    }

    /**
     * The redactor and the route mask must match the letters a real UUID has.
     */
    private static function uuid(): string
    {
        return UuidPlaceholder::withHexLetters(self::UUID);
    }

    private function clientThatAnswers(ResponseInterface ...$responses): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', retries: 0, apiKey: self::API_KEY),
            new RecordingHttpClient(array_values($responses)),
            $factory,
            $factory,
        );
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
