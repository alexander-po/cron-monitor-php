<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A logger that attaches a backtrace to its records, as an error tracker's
 * PSR-3 handler does: `debug_backtrace()` records frame arguments whatever
 * `zend.exception_ignore_args` says.
 *
 * Each SDK frame's arguments are printed during the call, because the
 * objects they hold keep changing after it returns, and an assertion raised
 * here would be swallowed by the client's guard around the logger.
 */
final class BacktraceRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, sdkFrames: list<array{frame: string, arguments: ?string}>}> */
    public array $records = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $frames = [];
        foreach (debug_backtrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (!str_starts_with($class, 'CronMonitor\\') || str_starts_with($class, 'CronMonitor\\Tests\\')) {
                continue;
            }
            $frames[] = [
                'frame' => $class.($frame['type'] ?? '').$frame['function'],
                'arguments' => \array_key_exists('args', $frame) ? print_r($frame['args'], true) : null,
            ];
        }
        $this->records[] = ['message' => (string) $message, 'sdkFrames' => $frames];
    }
}
