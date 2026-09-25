<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Cli;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Cli\SignupCommand;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CurlException;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SignupCommandTest extends TestCase
{
    private const DEVICE_CODE = 'dc_secret_value_4Qm9xT2vLr8Kp1Zs';

    private const TOKEN = 'cmk_Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z-_0';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->slept = [];
    }

    private function command(RecordingHttpClient $http, string $endpoint = 'https://cronheart.com'): SignupCommand
    {
        $factory = new HttpFactory();
        $api = new MonitorApiClient(new Configuration($endpoint), $http, $factory, $factory);

        return new SignupCommand($api, $endpoint, $this->stdout, $this->stderr, function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    private function stdout(): string
    {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }

    private function stderr(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function started(array $overrides = []): Response
    {
        return self::json(202, $overrides + [
            'device_code' => self::DEVICE_CODE,
            'user_code' => 'BCDF-GHJK',
            'expires_in' => 1800,
            'interval' => 5,
            'hint' => 'Check your mail.',
        ]);
    }

    private static function pending(): Response
    {
        return self::json(202, ['status' => 'authorization_pending']);
    }

    private static function issued(string $token = self::TOKEN, string $prefix = 'cmk_Ab3d'): Response
    {
        return self::json(200, ['token' => $token, 'token_prefix' => $prefix, 'project' => 'default']);
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

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_without_accepted_terms_it_names_both_documents_and_sends_nothing(): void
    {
        $http = new RecordingHttpClient([]);

        $status = $this->command($http, 'https://monitor.example.com/')->run('you@example.com', acceptTerms: false);

        self::assertSame(64, $status);
        self::assertCount(0, $http->requests);
        self::assertSame('', $this->stdout());
        self::assertStringContainsString('https://monitor.example.com/terms', $this->stderr());
        self::assertStringContainsString('https://monitor.example.com/privacy', $this->stderr());
        self::assertStringContainsString('--accept-terms', $this->stderr());
    }

    public function test_a_confirmed_signup_prints_only_the_env_line_on_stdout(): void
    {
        $http = new RecordingHttpClient([self::started(), self::pending(), self::issued()]);

        $status = $this->command($http)->run('you@example.com', acceptTerms: true);

        self::assertSame(0, $status, $this->stderr());
        self::assertSame('CRON_MONITOR_API_KEY='.self::TOKEN."\n", $this->stdout());
        self::assertStringContainsString("\n    BCDF-GHJK\n", $this->stderr(), 'the user code stands on its own line');
        self::assertStringContainsString('you@example.com', $this->stderr());
        self::assertStringContainsString('30 minutes', $this->stderr());
        self::assertStringContainsString('Confirmed: the account exists.', $this->stderr());
        self::assertStringContainsString('scoped to the project "default"', $this->stderr());
        self::assertStringContainsString('already has an account never confirms', $this->stderr());
        self::assertStringContainsString('Account, API tokens at https://cronheart.com', $this->stderr());
        self::assertStringNotContainsString(self::TOKEN, $this->stderr());
        self::assertStringNotContainsString(self::DEVICE_CODE, $this->stderr().$this->stdout());
        self::assertSame([5, 5], $this->slept, 'every poll waits the server interval first');
        self::assertCount(3, $http->requests);
    }

    public function test_a_slow_down_waits_what_the_server_asks_before_the_next_poll(): void
    {
        $http = new RecordingHttpClient([
            self::started(),
            self::problem(429, 'Slow down.', 'slow_down', ['Retry-After' => '12']),
            self::pending(),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([5, 12, 5], $this->slept);
    }

    public function test_a_slow_down_without_a_wait_falls_back_to_the_interval(): void
    {
        $http = new RecordingHttpClient([
            self::started(),
            self::problem(429, 'Slow down.', 'slow_down'),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([5, 5], $this->slept);
    }

    public function test_an_expired_request_stops_with_the_server_advice_and_no_token_line(): void
    {
        $detail = 'Request gone.';
        $http = new RecordingHttpClient([self::started(), self::pending(), self::problem(410, $detail, 'expired_token')]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame('', $this->stdout());
        self::assertStringContainsString('expired', $this->stderr());
        self::assertStringContainsString($detail, $this->stderr());
    }

    public function test_it_stops_polling_once_the_code_has_expired_locally(): void
    {
        $http = new RecordingHttpClient([self::started(['expires_in' => 10]), self::pending(), self::pending()]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame([5, 5], $this->slept);
        self::assertCount(3, $http->requests);
        self::assertSame('', $this->stdout());
        self::assertStringContainsString('expired before anyone confirmed it', $this->stderr());
    }

    public function test_an_interval_below_one_second_is_raised_to_one(): void
    {
        $http = new RecordingHttpClient([self::started(['interval' => 0]), self::issued()]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([1], $this->slept);
    }

    public function test_it_polls_through_an_outage_and_says_so_once(): void
    {
        $unreachable = new CurlException('Could not resolve host', (new HttpFactory())->createRequest('POST', 'https://cronheart.com/api/v1/signup/token'));
        $http = new RecordingHttpClient([
            self::started(),
            $unreachable,
            self::json(503, []),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame('CRON_MONITOR_API_KEY='.self::TOKEN."\n", $this->stdout());
        self::assertSame(1, substr_count($this->stderr(), 'Could not reach'));
    }

    public function test_a_refusal_during_polling_stops_it(): void
    {
        $http = new RecordingHttpClient([self::started(), self::problem(403, 'Signup from the API is switched off.', 'signup_disabled')]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertCount(2, $http->requests);
        self::assertSame('', $this->stdout());
        self::assertStringContainsString('Signup from the API is switched off.', $this->stderr());
    }

    public function test_a_throttled_start_reports_the_server_message(): void
    {
        $http = new RecordingHttpClient([self::problem(429, 'Rate limited.', 'rate_limited', ['Retry-After' => '120'])]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame([], $this->slept);
        self::assertStringContainsString('Rate limited.', $this->stderr());
    }

    public function test_a_rejected_address_lists_the_field_errors(): void
    {
        $http = new RecordingHttpClient([self::problem(422, 'One or more fields are invalid.', 'invalid_request', [], ['errors' => ['email' => 'This value is not a valid email address.']])]);

        self::assertSame(2, $this->command($http)->run('not-an-address', acceptTerms: true));
        self::assertStringContainsString('email: This value is not a valid email address.', $this->stderr());
    }

    public function test_a_plain_http_endpoint_is_a_usage_error(): void
    {
        $factory = new HttpFactory();
        $http = new RecordingHttpClient([]);
        $api = new MonitorApiClient(new Configuration('http://cron.internal', allowInsecureEndpoint: true), $http, $factory, $factory);
        $command = new SignupCommand($api, 'http://cron.internal', $this->stdout, $this->stderr, static function (int $seconds): void {
        });

        self::assertSame(64, $command->run('you@example.com', acceptTerms: true));
        self::assertCount(0, $http->requests);
        self::assertStringContainsString('plain HTTP', $this->stderr());
    }

    public function test_a_token_that_could_break_the_env_file_is_not_printed(): void
    {
        $http = new RecordingHttpClient([self::started(), self::issued("cmk_abc\nAPP_DEBUG=1", 'cmk_abc')]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame('', $this->stdout());
        self::assertStringNotContainsString('APP_DEBUG', $this->stderr());
        self::assertStringContainsString('Forgot password', $this->stderr());
        self::assertStringContainsString('revoke the token starting cmk_abc', $this->stderr());
    }

    public function test_an_unreadable_confirmation_stops_with_the_way_back_to_a_token(): void
    {
        $http = new RecordingHttpClient([
            self::started(),
            self::json(200, ['token_prefix' => 'cmk_Ab3d', 'project' => 'default']),
            self::problem(410, 'Request gone.', 'expired_token'),
        ]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertCount(2, $http->requests, 'polling on after the one-time answer only reaches the 410');
        self::assertSame('', $this->stdout());
        self::assertStringNotContainsString('Could not reach', $this->stderr());
        self::assertStringContainsString('the account exists', $this->stderr());
        self::assertStringContainsString('Forgot password', $this->stderr());
        self::assertStringContainsString('revoke the token issued by this signup', $this->stderr());
    }

    public function test_a_wait_shorter_than_the_interval_still_waits_the_interval(): void
    {
        $http = new RecordingHttpClient([
            self::started(),
            self::problem(429, 'Slow down.', 'slow_down', ['Retry-After' => '1']),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([5, 5], $this->slept);
    }

    public function test_a_wait_past_the_expiry_stops_at_once(): void
    {
        $http = new RecordingHttpClient([
            self::started(['expires_in' => 30]),
            self::problem(429, 'Slow down.', 'slow_down', ['Retry-After' => '3600']),
        ]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame([5], $this->slept);
        self::assertStringContainsString('past the code\'s expiry', $this->stderr());
    }

    public function test_no_poll_comes_sooner_than_the_interval_even_at_the_end(): void
    {
        $http = new RecordingHttpClient([self::started(['expires_in' => 12]), self::pending(), self::pending(), self::pending()]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertSame([5, 5], $this->slept);
        self::assertCount(3, $http->requests);
    }

    public function test_a_wait_that_ends_exactly_at_the_expiry_is_still_taken(): void
    {
        $http = new RecordingHttpClient([
            self::started(['expires_in' => 20]),
            self::problem(429, 'Slow down.', 'slow_down', ['Retry-After' => '15']),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([5, 15], $this->slept);
    }

    public function test_an_oversized_interval_is_capped_at_a_minute(): void
    {
        $http = new RecordingHttpClient([self::started(['interval' => 4294967296]), self::issued()]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame([60], $this->slept);
    }

    public function test_a_confirmation_without_a_project_is_announced_without_one(): void
    {
        $http = new RecordingHttpClient([self::started(), self::json(200, ['token' => self::TOKEN])]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame('CRON_MONITOR_API_KEY='.self::TOKEN."\n", $this->stdout());
        self::assertStringNotContainsString('scoped to the project', $this->stderr());
    }

    public function test_an_enormous_lifetime_does_not_break_the_countdown(): void
    {
        $http = new RecordingHttpClient([self::started(['expires_in' => \PHP_INT_MAX]), self::issued()]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
    }

    public function test_each_separate_outage_is_reported_once(): void
    {
        $factory = new HttpFactory();
        $http = new RecordingHttpClient([
            self::started(),
            new CurlException('Could not resolve host', $factory->createRequest('POST', 'https://cronheart.com/api/v1/signup/token')),
            self::pending(),
            new CurlException('Could not resolve host', $factory->createRequest('POST', 'https://cronheart.com/api/v1/signup/token')),
            self::issued(),
        ]);

        self::assertSame(0, $this->command($http)->run('you@example.com', acceptTerms: true), $this->stderr());
        self::assertSame(2, substr_count($this->stderr(), 'Could not reach'));
    }

    public function test_server_text_reaches_the_terminal_without_control_sequences(): void
    {
        $http = new RecordingHttpClient([
            self::started(['user_code' => "\e]0;owned\x07BCDF-GHJK\u{202E}"]),
            self::problem(410, "Gone.\e[2J", 'expired_token'),
        ]);

        self::assertSame(2, $this->command($http)->run('you@example.com', acceptTerms: true));
        self::assertStringContainsString('BCDF-GHJK', $this->stderr());
        self::assertStringNotContainsString("\e", $this->stderr());
        self::assertStringNotContainsString("\x07", $this->stderr());
        self::assertStringNotContainsString("\u{202E}", $this->stderr());
    }

    public function test_a_token_that_cannot_be_written_is_reported(): void
    {
        $readOnly = fopen('php://memory', 'r');
        self::assertIsResource($readOnly);
        $factory = new HttpFactory();
        $http = new RecordingHttpClient([self::started(), self::issued(self::TOKEN, self::TOKEN)]);
        $api = new MonitorApiClient(new Configuration('https://cronheart.com'), $http, $factory, $factory);
        $command = new SignupCommand($api, 'https://cronheart.com', $readOnly, $this->stderr, static function (int $seconds): void {
        });

        self::assertSame(2, $this->runRecordingDiagnostics($command, $diagnostics));
        self::assertSame([], $diagnostics, 'a diagnostic about the failed write can carry the token');
        self::assertStringContainsString('could not be written', $this->stderr());
        self::assertStringNotContainsString(self::TOKEN, $this->stderr(), 'a "prefix" that is the whole token is not named');
        self::assertStringContainsString('revoke the token issued by this signup', $this->stderr());
    }

    public function test_a_closed_stdout_pipe_loses_the_write_quietly(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$writer, $reader] = $pair;
        fclose($reader);
        $factory = new HttpFactory();
        $http = new RecordingHttpClient([self::started(), self::issued(self::TOKEN, substr(self::TOKEN, 0, -1))]);
        $api = new MonitorApiClient(new Configuration('https://cronheart.com'), $http, $factory, $factory);
        $command = new SignupCommand($api, 'https://cronheart.com', $writer, $this->stderr, static function (int $seconds): void {
        });

        self::assertSame(2, $this->runRecordingDiagnostics($command, $diagnostics));
        self::assertSame([], $diagnostics);
        self::assertStringContainsString('could not be written', $this->stderr());
        self::assertStringNotContainsString(substr(self::TOKEN, 0, -1), $this->stderr(), 'a "prefix" longer than half the token is not named');
        self::assertStringContainsString('revoke the token issued by this signup', $this->stderr());
    }

    /**
     * @param list<string>|null $diagnostics
     *
     * @param-out list<string> $diagnostics
     */
    private function runRecordingDiagnostics(SignupCommand $command, ?array &$diagnostics): int
    {
        $diagnostics = [];
        set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });
        try {
            return $command->run('you@example.com', acceptTerms: true);
        } finally {
            restore_error_handler();
        }
    }
}
