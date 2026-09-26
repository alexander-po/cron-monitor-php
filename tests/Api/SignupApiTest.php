<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Exception\ForbiddenException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\SignupExpiredException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CurlException;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SignupApiTest extends TestCase
{
    private const DEVICE_CODE = 'dc_secret_value_4Qm9xT2vLr8Kp1Zs';

    private function client(RecordingHttpClient $http, ?Configuration $configuration = null): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            $configuration ?? new Configuration('https://cronheart.com', apiKey: 'cmk_configured_token', retries: 3),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function started(): array
    {
        return [
            'device_code' => self::DEVICE_CODE,
            'user_code' => 'BCDF-GHJK',
            'expires_in' => 1800,
            'interval' => 5,
            'hint' => 'Check your mail.',
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $extra
     */
    private static function problem(int $status, string $detail, string $error, array $headers = [], array $extra = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
            (string) json_encode(['title' => 'Problem', 'status' => $status, 'detail' => $detail, 'error' => $error] + $extra),
        );
    }

    public function test_start_posts_the_address_and_the_consent_without_a_token(): void
    {
        $http = new RecordingHttpClient([self::json(202, self::started())]);

        $started = $this->client($http)->startSignup('you@example.com', acceptTerms: true);

        self::assertSame(self::DEVICE_CODE, $started->deviceCode);
        self::assertSame('BCDF-GHJK', $started->userCode);
        self::assertSame(1800, $started->expiresIn);
        self::assertSame(5, $started->interval);
        self::assertSame('Check your mail.', $started->hint);

        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/signup', (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertFalse($sent->hasHeader('Authorization'), 'the signup calls must not carry the configured token');
        self::assertSame(['email' => 'you@example.com', 'accept_terms' => true], json_decode($http->bodies[0], true));
    }

    public function test_start_tolerates_a_missing_hint(): void
    {
        $body = self::started();
        unset($body['hint']);
        $http = new RecordingHttpClient([self::json(202, $body)]);

        self::assertNull($this->client($http)->startSignup('you@example.com', acceptTerms: true)->hint);
    }

    public function test_start_without_the_terms_accepted_sends_nothing(): void
    {
        $http = new RecordingHttpClient([]);

        try {
            $this->client($http)->startSignup('you@example.com', acceptTerms: false);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('acceptTerms', $e->getMessage());
        }
        self::assertCount(0, $http->requests);
    }

    public function test_start_refuses_a_plain_http_endpoint_even_when_insecure_endpoints_are_allowed(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http, new Configuration('http://cron.internal', allowInsecureEndpoint: true));

        try {
            $client->startSignup('you@example.com', acceptTerms: true);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('plain HTTP', $e->getMessage());
        }
        self::assertCount(0, $http->requests);
    }

    public function test_poll_refuses_a_plain_http_endpoint_before_the_device_code_leaves(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http, new Configuration('http://cron.internal', allowInsecureEndpoint: true));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->pollSignupToken(self::DEVICE_CODE);
        } finally {
            self::assertCount(0, $http->requests);
        }
    }

    public function test_start_is_not_retried_on_a_server_error(): void
    {
        $http = new RecordingHttpClient([self::json(503, []), self::json(202, self::started())]);

        try {
            $this->client($http)->startSignup('you@example.com', acceptTerms: true);
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'a replayed start mails the address again');
    }

    public function test_start_maps_a_rejected_address_to_a_validation_exception(): void
    {
        $http = new RecordingHttpClient([self::problem(422, 'One or more fields are invalid.', 'invalid_request', [], ['errors' => ['email' => 'This value is not a valid email address.']])]);

        try {
            $this->client($http)->startSignup('not-an-address', acceptTerms: true);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['email' => 'This value is not a valid email address.'], $e->errors);
        }
    }

    public function test_start_maps_the_signup_throttle_to_a_rate_limit_with_its_wait(): void
    {
        $http = new RecordingHttpClient([self::problem(429, 'Rate limited.', 'rate_limited', ['Retry-After' => '120'])]);

        try {
            $this->client($http)->startSignup('you@example.com', acceptTerms: true);
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('Rate limited.', $e->getMessage());
        }
    }

    public function test_start_maps_signup_switched_off_to_forbidden(): void
    {
        $http = new RecordingHttpClient([self::problem(403, 'Signup from the API is switched off.', 'signup_disabled')]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Signup from the API is switched off.');
        $this->client($http)->startSignup('you@example.com', acceptTerms: true);
    }

    public function test_start_with_a_malformed_answer_is_a_transport_exception(): void
    {
        $body = self::started();
        unset($body['device_code']);
        $http = new RecordingHttpClient([self::json(202, $body)]);

        $this->expectException(ApiTransportException::class);
        $this->client($http)->startSignup('you@example.com', acceptTerms: true);
    }

    public function test_poll_is_null_while_the_person_has_not_confirmed(): void
    {
        $http = new RecordingHttpClient([self::json(202, ['status' => 'authorization_pending'])]);

        self::assertNull($this->client($http)->pollSignupToken(self::DEVICE_CODE));

        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/signup/token', (string) $sent->getUri());
        self::assertFalse($sent->hasHeader('Authorization'));
        self::assertSame(['device_code' => self::DEVICE_CODE], json_decode($http->bodies[0], true));
    }

    public function test_poll_returns_the_token_once_confirmed(): void
    {
        $http = new RecordingHttpClient([self::json(200, ['token' => 'cmk_new_token', 'token_prefix' => 'cmk_new_', 'project' => 'default'])]);

        $token = $this->client($http)->pollSignupToken(self::DEVICE_CODE);

        self::assertNotNull($token);
        self::assertSame('cmk_new_token', $token->token);
        self::assertSame('cmk_new_', $token->tokenPrefix);
        self::assertSame('default', $token->project);
    }

    public function test_a_confirmation_needs_only_the_token(): void
    {
        $http = new RecordingHttpClient([self::json(200, ['token' => 'cmk_new_token'])]);

        $token = $this->client($http)->pollSignupToken(self::DEVICE_CODE);

        self::assertNotNull($token);
        self::assertSame('cmk_new_token', $token->token);
        self::assertNull($token->tokenPrefix);
        self::assertNull($token->project);
    }

    public function test_a_confirmation_without_a_token_is_a_transport_exception_carrying_its_status(): void
    {
        $http = new RecordingHttpClient([self::json(200, ['token_prefix' => 'cmk_new_', 'project' => 'default'])]);

        try {
            $this->client($http)->pollSignupToken(self::DEVICE_CODE);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            self::assertSame(200, $e->statusCode, 'a caller must tell an unreadable confirmation from an outage');
        }
    }

    public function test_a_gone_from_any_other_call_stays_the_generic_parent(): void
    {
        $http = new RecordingHttpClient([self::problem(410, 'Request gone.', 'expired_token')]);

        try {
            $this->client($http)->startSignup('you@example.com', acceptTerms: true);
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertNotInstanceOf(SignupExpiredException::class, $e);
            self::assertSame(410, $e->statusCode);
        }
    }

    public function test_poll_maps_gone_to_signup_expired_with_the_recovery_detail(): void
    {
        $detail = 'Request gone.';
        $http = new RecordingHttpClient([self::problem(410, $detail, 'expired_token')]);

        try {
            $this->client($http)->pollSignupToken(self::DEVICE_CODE);
            self::fail('Expected a SignupExpiredException.');
        } catch (SignupExpiredException $e) {
            self::assertInstanceOf(UnexpectedResponseException::class, $e, 'catching the parent must keep working');
            self::assertSame(410, $e->statusCode);
            self::assertSame($detail, $e->detail);
            self::assertSame(UnexpectedResponseException::class, $e->getPrevious() ? $e->getPrevious()::class : null);
        }
    }

    public function test_poll_maps_slow_down_to_a_rate_limit_with_its_wait(): void
    {
        $http = new RecordingHttpClient([self::problem(429, 'Slow down.', 'slow_down', ['Retry-After' => '5'])]);

        try {
            $this->client($http)->pollSignupToken(self::DEVICE_CODE);
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(5, $e->retryAfter);
        }
    }

    public function test_poll_is_not_retried_on_a_server_error(): void
    {
        $http = new RecordingHttpClient([self::json(502, []), self::json(202, ['status' => 'authorization_pending'])]);

        try {
            $this->client($http)->pollSignupToken(self::DEVICE_CODE);
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertNotInstanceOf(SignupExpiredException::class, $e);
            self::assertSame(502, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'the caller\'s poll loop is the retry');
    }

    public function test_poll_rejects_an_empty_device_code_before_any_request(): void
    {
        $http = new RecordingHttpClient([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($http)->pollSignupToken('');
        } finally {
            self::assertCount(0, $http->requests);
        }
    }

    public function test_a_failed_poll_keeps_the_device_code_out_of_the_rendered_exception(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $http = new RecordingHttpClient([new CurlException('Could not resolve host', (new HttpFactory())->createRequest('POST', 'https://cronheart.com/api/v1/signup/token'))]);

            try {
                $this->client($http)->pollSignupToken(self::DEVICE_CODE);
                self::fail('Expected an ApiTransportException.');
            } catch (ApiTransportException $e) {
                $rendered = (string) $e;
                self::assertStringContainsString('pollSignupToken', $rendered, 'the trace must reach the frame that took the device code');
                self::assertStringNotContainsString(substr(self::DEVICE_CODE, 0, 12), $rendered);
                self::assertStringNotContainsString(self::DEVICE_CODE, self::argumentsBelowTheTest($e));
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    public function test_an_unreadable_confirmation_keeps_the_token_out_of_trace_arguments(): void
    {
        $token = 'cmk_Zq8Wv3Tn6Yb1Xc4Ld7Mf0Ks2';
        $http = new RecordingHttpClient([self::json(200, ['token' => $token, 'project' => ['not' => 'a string']])]);

        $this->assertSecretStaysOutOfTraces($token, fn () => $this->client($http)->pollSignupToken(self::DEVICE_CODE));
    }

    public function test_an_unreadable_start_keeps_the_device_code_out_of_trace_arguments(): void
    {
        $http = new RecordingHttpClient([self::json(202, ['device_code' => self::DEVICE_CODE, 'user_code' => 42])]);

        $this->assertSecretStaysOutOfTraces(self::DEVICE_CODE, fn () => $this->client($http)->startSignup('you@example.com', acceptTerms: true));
    }

    public function test_a_confirmation_that_is_not_json_keeps_the_token_out_of_trace_arguments(): void
    {
        $token = 'cmk_Rt5Yu8Io1Pa4Sd7Fg0Hj3Kl6';
        $http = new RecordingHttpClient([new Response(200, ['Content-Type' => 'application/json'], '{"token":"'.$token.'"}<br /><b>Notice</b>')]);

        $e = $this->assertSecretStaysOutOfTraces($token, fn () => $this->client($http)->pollSignupToken(self::DEVICE_CODE));
        self::assertSame(200, $e->statusCode);
    }

    public function test_a_token_of_the_wrong_type_stays_out_of_trace_arguments(): void
    {
        $token = 'cmk_Wq2Er5Ty8Ui1Op4As7Df0Gh3';
        $http = new RecordingHttpClient([self::json(200, ['token' => ['value' => $token]])]);

        $this->assertSecretStaysOutOfTraces($token, fn () => $this->client($http)->pollSignupToken(self::DEVICE_CODE));
    }

    public function test_an_unreadable_rotated_secret_stays_out_of_trace_arguments(): void
    {
        $secret = 'whsec_Mn4Bv7Cx0Za3Lk6Jh9Gf2Ds5';
        $http = new RecordingHttpClient([self::json(200, [
            'id' => '7',
            'kind' => 'webhook',
            'label' => 'Ops webhook',
            'verified' => 'yes',
            'config' => [],
            'created_at' => '2026-01-01T00:00:00+00:00',
            'secret' => $secret,
        ])]);

        $this->assertSecretStaysOutOfTraces($secret, fn () => $this->client($http)->rotateChannelSecret('7'));
    }

    public function test_a_failed_poll_logs_the_route_and_not_the_device_code(): void
    {
        $logger = new InMemoryLogger();
        $factory = new HttpFactory();
        $http = new RecordingHttpClient([new CurlException('Could not resolve host', $factory->createRequest('POST', 'https://cronheart.com/api/v1/signup/token'))]);
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', retries: 0), $http, $factory, $factory, $logger);

        try {
            $client->pollSignupToken(self::DEVICE_CODE);
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException) {
        }

        self::assertSame('/api/v1/signup/token', $logger->records[0]['context']['route'] ?? null);
        self::assertStringNotContainsString(self::DEVICE_CODE, json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertSecretStaysOutOfTraces(#[\SensitiveParameter] string $secret, \Closure $call): ApiTransportException
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $call();
            self::fail('Expected an ApiTransportException.');
        } catch (ApiTransportException $e) {
            for ($link = $e; null !== $link; $link = $link->getPrevious()) {
                self::assertStringNotContainsString($secret, self::argumentsBelowTheTest($link));
            }
            self::assertStringNotContainsString(substr($secret, 0, 12), (string) $e);

            return $e;
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    /**
     * The arguments of every frame between the throw and the test, built-in
     * functions included. The test's own frames and PHPUnit's hold the
     * fixtures, so the walk stops there.
     */
    private static function argumentsBelowTheTest(\Throwable $e): string
    {
        $arguments = [];
        foreach ($e->getTrace() as $frame) {
            if (1 === preg_match('/^(CronMonitor\\\\Tests|PHPUnit)\\\\/', (string) ($frame['class'] ?? ''))) {
                break;
            }
            $arguments[] = $frame['args'] ?? [];
        }
        self::assertNotSame([], $arguments, 'no frame below the test, so the check would be vacuous');

        return print_r($arguments, true);
    }
}
