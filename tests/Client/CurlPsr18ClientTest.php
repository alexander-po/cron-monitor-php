<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Client;

use CronMonitor\Client\CurlException;
use CronMonitor\Client\CurlPsr18Client;
use CronMonitor\Tests\Support\LocalHttpServer;
use CronMonitor\Tests\Support\SecretTraceAssertions;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class CurlPsr18ClientTest extends TestCase
{
    use SecretTraceAssertions;

    private const TOKEN = 'cmk_Hd5Wq2Ys8Ka3Pe7Tn4Gv';

    private const UUID = '3c7e9b12-5d4f-4a86-b0e3-8f1a6c2d9e47';

    private static ?LocalHttpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        $server = LocalHttpServer::start();
        if (null === $server) {
            self::markTestSkipped('Could not start the built-in PHP HTTP server.');
        }
        self::$server = $server;
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$server) {
            self::$server->stop();
            self::$server = null;
        }
    }

    public function test_constructor_rejects_non_positive_timeout(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(\InvalidArgumentException::class);
        new CurlPsr18Client($factory, $factory, 0.0);
    }

    public function test_round_trip_against_local_php_server(): void
    {
        self::assertNotNull(self::$server);

        $factory = new Psr17Factory();
        $client = new CurlPsr18Client($factory, $factory, 5.0);
        $request = $factory->createRequest('POST', self::$server->baseUrl().'/ping/abc')
            ->withHeader('X-Custom-Header', 'foo')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($factory->createStream('payload-body'));

        $response = $client->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{method: string, uri: string, body: string, headers: array<string, string>} $data */
        $data = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('POST', $data['method']);
        self::assertSame('/ping/abc', $data['uri']);
        self::assertSame('payload-body', $data['body']);
        self::assertSame('foo', $data['headers']['x-custom-header']);

        // Response headers from the fixture must round-trip through the
        // header callback into the PSR-7 response.
        self::assertSame('POST', $response->getHeaderLine('X-Echo-Method'));
        self::assertSame('/ping/abc', $response->getHeaderLine('X-Echo-Path'));
    }

    public function test_non_2xx_status_is_propagated_without_throwing(): void
    {
        self::assertNotNull(self::$server);

        $factory = new Psr17Factory();
        $client = new CurlPsr18Client($factory, $factory, 5.0);
        $request = $factory->createRequest('POST', self::$server->baseUrl().'/?status=503');

        $response = $client->sendRequest($request);

        self::assertSame(503, $response->getStatusCode());
    }

    public function test_unreachable_endpoint_throws_psr18_network_exception(): void
    {
        $factory = new Psr17Factory();
        // Port 1 is reserved (RFC 6335) and either refuses or filters
        // connections in every reasonable test environment. Combined with a
        // sub-second connect timeout, the curl handle fails fast.
        $client = new CurlPsr18Client($factory, $factory, 1.0);
        $request = $factory->createRequest('POST', 'http://127.0.0.1:1/ping/xx');

        try {
            $client->sendRequest($request);
            self::fail('Expected CurlException to be thrown.');
        } catch (CurlException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(ClientExceptionInterface::class, $e);
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertStringContainsString('cURL error', $e->getMessage());
        }
    }

    public function test_a_refused_connection_keeps_the_bearer_token_and_the_monitor_uuid_out_of_trace_arguments(): void
    {
        $factory = new Psr17Factory();
        $client = new CurlPsr18Client($factory, $factory, 1.0);
        $request = self::authenticatedPing('http://127.0.0.1:1');

        $this->assertSecretStaysOutOfTraces(self::TOKEN, static fn () => $client->sendRequest($request), CurlException::class);
        $this->assertSecretStaysOutOfTraces(self::UUID, static fn () => $client->sendRequest($request), CurlException::class);
    }

    public function test_a_printed_transport_failure_keeps_the_request_it_carries_hidden(): void
    {
        $request = self::authenticatedPing('https://cronheart.com');

        self::assertSame($request, (new CurlException('cURL error (7): Failed to connect.', $request))->getRequest());
        $printed = [
            self::printedWithoutFrameArguments(static fn () => throw new CurlException('cURL error (7): Failed to connect.', $request), CurlException::class),
            self::printedWithoutFrameArguments(static fn () => throw new \RuntimeException('Wrapped.', 0, new CurlException('cURL error (7): Failed to connect.', $request)), \RuntimeException::class),
        ];
        foreach ($printed as $dump) {
            self::assertStringNotContainsString(self::TOKEN, $dump);
            self::assertStringNotContainsString(self::UUID, $dump);
        }
    }

    private static function authenticatedPing(string $endpoint): RequestInterface
    {
        return (new Psr17Factory())->createRequest('POST', $endpoint.'/ping/'.self::UUID)
            ->withHeader('Authorization', 'Bearer '.self::TOKEN);
    }
}
