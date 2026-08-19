<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Client;

use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * The monitor UUID is the only credential on the ping endpoint: whoever holds
 * it can post any state for that monitor. So it must not reach the host's log
 * files, where it outlives the job and travels wherever the logs are shipped.
 *
 * What the SDK logs instead is the truncated SHA-256 the server logs, so an
 * SDK line and a server line can still be joined on one monitor during an
 * incident without either side holding the credential.
 */
final class CronMonitorClientLoggingTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function test_failure_warning_carries_the_hashed_monitor_uuid(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientWith(
            new RecordingHttpClient([$this->transportError('connection refused')]),
            $logger,
        );

        $client->heartbeat(self::UUID);

        $warning = $this->recordAt('warning', $logger);
        self::assertSame(
            substr(hash('sha256', self::UUID), 0, 16),
            $warning['context']['monitor_uuid_hash'] ?? null,
        );
    }

    public function test_the_hash_does_not_depend_on_how_the_uuid_was_spelled(): void
    {
        // The ping URL accepts a UUID case-insensitively while the server
        // stores one canonical spelling. If the digest followed the spelling,
        // two call sites naming the same monitor would land under two keys and
        // the join this format exists for would quietly stop working.
        $lower = new InMemoryLogger();
        $upper = new InMemoryLogger();

        $this->clientWith(new RecordingHttpClient([$this->transportError('boom')]), $lower)
            ->heartbeat(strtolower(self::UUID));
        $this->clientWith(new RecordingHttpClient([$this->transportError('boom')]), $upper)
            ->heartbeat(strtoupper(self::UUID));

        self::assertSame(
            $this->recordAt('warning', $lower)['context']['monitor_uuid_hash'] ?? null,
            $this->recordAt('warning', $upper)['context']['monitor_uuid_hash'] ?? null,
        );
        self::assertRawUuidAbsent($upper);
    }

    public function test_an_api_key_quoted_by_the_transport_is_removed(): void
    {
        // A transport that echoes request headers would otherwise put an
        // account-wide token into the host's log file.
        $logger = new InMemoryLogger();
        $quoted = $this->transportError('rejected request [Authorization: Bearer cmk_example_notarealtoken] to /ping');
        $client = $this->clientWith(new RecordingHttpClient([$quoted]), $logger);

        $result = $client->heartbeat(self::UUID);

        self::assertStringNotContainsString(
            'cmk_example_notarealtoken',
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
        self::assertIsString($result->errorMessage);
        self::assertStringNotContainsString('cmk_example_notarealtoken', $result->errorMessage);
    }

    public function test_a_short_identifier_leaves_the_diagnostic_readable(): void
    {
        // Redaction by substring would rewrite every "a" in the sentence, on
        // exactly the path where an operator needs to read it.
        $logger = new InMemoryLogger();
        $client = $this->clientWith(new RecordingHttpClient([]), $logger);

        $result = $client->ping('a', null, null);

        self::assertSame('The monitor identifier is not a valid cron-monitor UUID.', $result->errorMessage);
        self::assertSame($result->errorMessage, $this->recordAt('error', $logger)['context']['error'] ?? null);
    }

    public function test_failure_warning_never_carries_the_raw_monitor_uuid(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientWith(
            new RecordingHttpClient([$this->transportError('connection refused')]),
            $logger,
        );

        $client->heartbeat(self::UUID);

        self::assertRawUuidAbsent($logger);
    }

    public function test_transport_error_quoting_the_ping_url_is_scrubbed(): void
    {
        // Guzzle appends the full request URI to its connection errors, and
        // for a ping that URI *is* the credential.
        $quoted = $this->transportError(
            'cURL error 7: Failed to connect for https://cronheart.com/ping/'.self::UUID.'/success',
        );
        $logger = new InMemoryLogger();
        $client = $this->clientWith(new RecordingHttpClient([$quoted]), $logger);

        $result = $client->success(self::UUID, 'ok');

        self::assertRawUuidAbsent($logger);
        self::assertIsString($result->errorMessage);
        self::assertStringNotContainsStringIgnoringCase(self::UUID, $result->errorMessage);
    }

    public function test_bad_uuid_error_never_carries_the_raw_identifier(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->clientWith(new RecordingHttpClient([]), $logger);

        $result = $client->ping(self::UUID.'-trailing-junk', null, null);

        $error = $this->recordAt('error', $logger);
        self::assertSame(
            substr(hash('sha256', strtolower(self::UUID.'-trailing-junk')), 0, 16),
            $error['context']['monitor_uuid_hash'] ?? null,
        );
        self::assertRawUuidAbsent($logger);
        self::assertIsString($result->errorMessage);
        self::assertStringNotContainsStringIgnoringCase(self::UUID, $result->errorMessage);
    }

    private static function assertRawUuidAbsent(InMemoryLogger $logger): void
    {
        self::assertNotSame([], $logger->records);
        self::assertStringNotContainsStringIgnoringCase(
            self::UUID,
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function recordAt(string $level, InMemoryLogger $logger): array
    {
        foreach ($logger->records as $record) {
            if ($level === $record['level']) {
                return $record;
            }
        }

        self::fail(\sprintf('No %s record was logged.', $level));
    }

    private function transportError(string $message): \Throwable
    {
        return new class($message) extends \RuntimeException implements ClientExceptionInterface {};
    }

    private function clientWith(RecordingHttpClient $http, InMemoryLogger $logger): CronMonitorClient
    {
        $factory = new HttpFactory();

        return new CronMonitorClient(
            new Configuration('https://cronheart.com', retries: 0),
            $http,
            $factory,
            $factory,
            $logger,
        );
    }
}
