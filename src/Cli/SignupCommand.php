<?php

declare(strict_types=1);

namespace CronMonitor\Cli;

use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\SignupExpiredException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\Internal\SecretRedactor;
use CronMonitor\Api\MonitorApiClient;

/**
 * `cron-monitor signup <email> --accept-terms`: start a signup, show the person
 * the code to type on the page the mail links to, poll until they confirm, and
 * print the new token as the line for an `.env` file.
 *
 * Standard output carries that one line and nothing else, so redirecting it
 * into the env file stores the token without it ever reaching the screen;
 * everything meant for the person goes to standard error.
 *
 * @internal the implementation behind `vendor/bin/cron-monitor signup`; the
 *           command line is the supported surface
 */
final class SignupCommand
{
    private const EXIT_OK = 0;

    private const EXIT_FAILED = 2;

    private const EXIT_USAGE = 64;

    private const MAX_LIFETIME_SECONDS = 3600;

    private const MAX_INTERVAL_SECONDS = 60;

    /**
     * @param resource            $stdout
     * @param resource            $stderr
     * @param \Closure(int): void $sleep
     */
    public function __construct(
        private readonly MonitorApiClient $api,
        private readonly string $endpoint,
        private $stdout,
        private $stderr,
        private readonly \Closure $sleep,
    ) {
    }

    public function run(string $email, bool $acceptTerms): int
    {
        $site = rtrim($this->endpoint, '/');

        if (!$acceptTerms) {
            $this->say(\sprintf(
                "Signing up means agreeing to the Terms of Service (%s/terms) and the Privacy Policy (%s/privacy).\nRead them, then run the command again with --accept-terms.",
                $site,
                $site,
            ));

            return self::EXIT_USAGE;
        }

        try {
            $started = $this->api->startSignup($email, true);
        } catch (\InvalidArgumentException $e) {
            $this->say('Configuration error: '.$e->getMessage());

            return self::EXIT_USAGE;
        } catch (ApiException $e) {
            $this->report('The signup did not start', $e);

            return self::EXIT_FAILED;
        }

        $lifetime = min(max(0, $started->expiresIn), self::MAX_LIFETIME_SECONDS);
        $minutes = intdiv($lifetime, 60) + (0 === $lifetime % 60 ? 0 : 1);
        $this->say(\sprintf(
            "A mail with a confirmation link is on its way to %s.\nOpen the link and type this code on the page:\n\n    %s\n\nWaiting for the confirmation; the code expires in %d minute%s. Press Ctrl+C to stop.\nAn address that already has an account never confirms (an active account gets a mail saying so instead): stop here and create a token under Account, API tokens at %s.",
            self::printable($email),
            self::printable($started->userCode),
            $minutes,
            1 === $minutes ? '' : 's',
            $site,
        ));

        $interval = min(max(1, $started->interval), self::MAX_INTERVAL_SECONDS);
        $remaining = $lifetime;
        $wait = $interval;
        $reachable = true;

        while ($wait <= $remaining) {
            ($this->sleep)($wait);
            $remaining -= $wait;
            $wait = $interval;

            try {
                $token = $this->api->pollSignupToken($started->deviceCode);
            } catch (RateLimitException $e) {
                $wait = max($interval, $e->retryAfter ?? $interval);
                if ($wait > $remaining) {
                    $this->say(\sprintf('The server asks to wait %d seconds before the next poll, past the code\'s expiry. Run the command again later.', $wait));

                    return self::EXIT_FAILED;
                }

                continue;
            } catch (SignupExpiredException $e) {
                $this->report('The signup request expired, was cancelled or was already used', $e);

                return self::EXIT_FAILED;
            } catch (ApiException $e) {
                if ($e instanceof ApiTransportException && null !== $e->statusCode) {
                    $this->say(\sprintf('The server answered, but not in a form this command can read (%s). If the code was confirmed, the account exists: %s', self::printable($e->getMessage()), $this->orphanAdvice(null, '')));

                    return self::EXIT_FAILED;
                }
                if (!$e instanceof ApiTransportException && ($e->statusCode ?? 0) < 500) {
                    $this->report('The signup stopped', $e);

                    return self::EXIT_FAILED;
                }
                if ($reachable) {
                    $this->say(\sprintf('Could not reach %s (%s). Still waiting.', $site, self::printable($e->getMessage())));
                }
                $reachable = false;

                continue;
            }
            $reachable = true;

            if (null === $token) {
                continue;
            }

            if (1 !== preg_match('/^'.SecretRedactor::API_KEY_PATTERN.'$/D', $token->token)) {
                $this->say('Confirmed, but the server answered with a token in a shape this command does not write, so it was not printed. The account exists: '.$this->orphanAdvice($token->tokenPrefix, $token->token));

                return self::EXIT_FAILED;
            }

            $line = 'CRON_MONITOR_API_KEY='.$token->token."\n";
            if (\strlen($line) !== $this->writeQuietly($line)) {
                $this->say('Confirmed, but the token could not be written to standard output. The account exists: '.$this->orphanAdvice($token->tokenPrefix, $token->token));

                return self::EXIT_FAILED;
            }

            $this->say(\sprintf(
                "Confirmed: the account exists. Its API token%s is the CRON_MONITOR_API_KEY line on standard output and is not shown again. Keep it in your .env file or secret store, never in a committed file.\nThe account has no password yet: to sign in at %s, use \"Forgot password\" on the sign-in page.",
                null === $token->project ? '' : \sprintf(', scoped to the project "%s",', self::printable($token->project)),
                $site,
            ));

            return self::EXIT_OK;
        }

        $this->say('The code expired before anyone confirmed it. Run the command again for a new one.');

        return self::EXIT_FAILED;
    }

    /**
     * The way back to a token when the account exists but this command did not
     * hand one over: the account has no password yet, and a token issued but
     * not printed stays live until it is revoked. The prefix is named only when
     * it is a genuine start of the token and at most half of it.
     */
    private function orphanAdvice(?string $tokenPrefix, #[\SensitiveParameter] string $token): string
    {
        $named = null !== $tokenPrefix && '' !== $tokenPrefix && 2 * \strlen($tokenPrefix) <= \strlen($token) && str_starts_with($token, $tokenPrefix);

        return \sprintf(
            'set its password with "Forgot password" on the sign-in page at %s, then under Account, API tokens revoke %s and create a new one.',
            rtrim($this->endpoint, '/'),
            $named ? 'the token starting '.self::printable($tokenPrefix) : 'the token issued by this signup',
        );
    }

    /**
     * A failed write raises a diagnostic whose stack trace, under a debugger
     * such as Xdebug, carries the written line and so the token. The byte
     * count already reports the failure, so the diagnostic is swallowed.
     */
    private function writeQuietly(#[\SensitiveParameter] string $line): int|false
    {
        set_error_handler(static fn (): bool => true);
        try {
            return fwrite($this->stdout, $line);
        } finally {
            restore_error_handler();
        }
    }

    private function report(string $what, ApiException $e): void
    {
        $lines = [$what.': '.self::printable($e->getMessage())];
        if ($e instanceof ValidationException) {
            foreach ($e->errors as $field => $message) {
                $lines[] = \sprintf('  %s: %s', self::printable($field), self::printable($message));
            }
        }

        $this->say(implode("\n", $lines));
    }

    private function say(string $text): void
    {
        fwrite($this->stderr, $text."\n");
    }

    /**
     * Text from the server, cleared of control and format characters before it
     * reaches a terminal, where an escape sequence could rewrite what the
     * person reads.
     */
    private static function printable(string $text): string
    {
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);

        return \is_string($clean) ? $clean : (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text);
    }
}
