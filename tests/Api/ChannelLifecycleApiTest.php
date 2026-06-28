<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\Channel;
use CronMonitor\Api\Dto\CreateChannelRequest;
use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\ChannelDeliveryException;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ChannelLifecycleApiTest extends TestCase
{
    private function client(RecordingHttpClient $http, int $retries = 0): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: $retries),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function channelRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '7',
            'kind' => 'webhook',
            'label' => 'Ops webhook',
            'verified' => true,
            'config' => ['url' => '***'],
            'created_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_create_channel_posts_snake_case_body(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(201, self::channelRow(['kind' => 'email', 'label' => 'My inbox', 'config' => ['address' => 'me@example.test']]))]);
        $client = $this->client($http);

        $request = CreateChannelRequest::email('My inbox', 'me@example.test');
        $channel = $client->createChannel($request);

        self::assertInstanceOf(Channel::class, $channel);
        self::assertSame('email', $channel->kind);

        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels', (string) $sent->getUri());
        self::assertSame('Bearer cmk_test_token', $sent->getHeaderLine('Authorization'));
        self::assertSame($request->toArray(), json_decode((string) $sent->getBody(), true));
    }

    public function test_create_channel_is_not_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $client = $this->client($http, retries: 3);

        try {
            $client->createChannel(CreateChannelRequest::email('X', 'me@example.test'));
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'channel create must not be retried');
    }

    public function test_get_channel_parses_object(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::channelRow())]);
        $client = $this->client($http);

        $channel = $client->getChannel('7');

        self::assertSame('7', $channel->id);
        self::assertSame('https://cronheart.com/api/v1/channels/7', (string) $http->requests[0]->getUri());
    }

    public function test_get_channel_rejects_non_positive_id_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->getChannel('0');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_get_channel_rejects_a_non_numeric_id_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->getChannel('not-an-id');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_update_channel_sends_label(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::channelRow(['label' => 'Renamed']))]);
        $client = $this->client($http);

        $channel = $client->updateChannel('7', 'Renamed');

        self::assertSame('Renamed', $channel->label);
        $sent = $http->requests[0];
        self::assertSame('PATCH', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7', (string) $sent->getUri());
        self::assertSame(['label' => 'Renamed'], json_decode((string) $sent->getBody(), true));
    }

    public function test_update_channel_rejects_blank_label_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->updateChannel('7', '   ');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_update_channel_is_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(200, self::channelRow())]);
        $client = $this->client($http, retries: 1);

        $client->updateChannel('7', 'Renamed');

        self::assertCount(2, $http->requests);
    }

    public function test_delete_channel_succeeds_on_204(): void
    {
        $http = new RecordingHttpClient([new Response(204, [], '')]);
        $client = $this->client($http);

        $client->deleteChannel('7');

        $sent = $http->requests[0];
        self::assertSame('DELETE', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7', (string) $sent->getUri());
    }

    public function test_delete_channel_maps_404(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(404, ['title' => 'Not Found'])]);
        $client = $this->client($http);

        $this->expectException(NotFoundException::class);
        $client->deleteChannel('7');
    }

    public function test_rotate_channel_secret_returns_plaintext_once(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::channelRow(['secret' => 'whsec_freshly_minted']))]);
        $client = $this->client($http);

        $result = $client->rotateChannelSecret('7');

        self::assertSame('whsec_freshly_minted', $result->secret);
        self::assertSame('7', $result->channel->id);
        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7/rotate-secret', (string) $sent->getUri());
        self::assertSame('', (string) $sent->getBody());
    }

    public function test_rotate_channel_secret_is_not_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $client = $this->client($http, retries: 3);

        try {
            $client->rotateChannelSecret('7');
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'secret rotation must not be retried');
    }

    public function test_test_channel_returns_delivered_result(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, [
            'delivered' => true,
            'channel' => self::channelRow(['verified' => true]),
            'newly_verified' => true,
        ])]);
        $client = $this->client($http);

        $result = $client->testChannel('7');

        self::assertTrue($result->delivered);
        self::assertTrue($result->newlyVerified);
        self::assertSame('7', $result->channel->id);
        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7/test', (string) $sent->getUri());
    }

    public function test_test_channel_maps_502_to_channel_delivery_exception(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(502, [
            'title' => 'Bad Gateway',
            'detail' => 'Test delivery failed. Check the destination configuration.',
        ])]);
        $client = $this->client($http);

        try {
            $client->testChannel('7');
            self::fail('Expected an exception.');
        } catch (ChannelDeliveryException $e) {
            self::assertSame(502, $e->statusCode);
            self::assertSame('Test delivery failed. Check the destination configuration.', $e->detail);
            $previous = $e->getPrevious();
            self::assertInstanceOf(UnexpectedResponseException::class, $previous);
            self::assertNotInstanceOf(ChannelDeliveryException::class, $previous);
            self::assertSame(502, $previous->statusCode);
        }
    }

    public function test_test_channel_502_stays_catchable_as_unexpected_response(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(502, [
            'detail' => 'Test delivery failed. Check the destination configuration.',
        ])]);
        $client = $this->client($http);

        try {
            $client->testChannel('7');
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertInstanceOf(ChannelDeliveryException::class, $e);
            self::assertInstanceOf(ApiException::class, $e);
            self::assertSame(502, $e->statusCode);
        }
    }

    public function test_test_channel_maps_422_unverified(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(422, [
            'detail' => 'This email channel is not yet verified. Click the verification link first.',
        ])]);
        $client = $this->client($http);

        $this->expectException(ValidationException::class);
        $client->testChannel('7');
    }

    public function test_test_channel_is_not_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $client = $this->client($http, retries: 3);

        try {
            $client->testChannel('7');
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
            self::assertNotInstanceOf(ChannelDeliveryException::class, $e);
        }
        self::assertCount(1, $http->requests, 'a test send must not be retried');
    }
}
