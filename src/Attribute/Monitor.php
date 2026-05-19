<?php

declare(strict_types=1);

namespace CronMonitor\Attribute;

/**
 * Marks a command class (Symfony Console / Laravel Artisan) as a
 * cron-monitor target, without forcing the user to maintain a parallel
 * `command-name → uuid` map in YAML or a config array.
 *
 *     #[Monitor(uuid: '550e8400-e29b-41d4-a716-446655440000')]
 *     final class GenerateNightlyReportCommand extends Command { ... }
 *
 * Precedence: explicit YAML / config maps still win over an attribute
 * value, because users sometimes want to override an attribute UUID per
 * environment (e.g. blank in dev, populated from an env var in prod).
 *
 * Lives at the package root namespace — not under `Bridge/Symfony/` —
 * because the same attribute is intended to be honoured by the Laravel
 * scheduler bridge as well. Keeping it framework-neutral keeps a single
 * source of truth and avoids users having to choose between two
 * differently-namespaced attributes when their app boots multiple
 * frameworks (rare, but real for command-line tools embedded in larger
 * stacks).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Monitor
{
    public function __construct(
        public readonly string $uuid,
    ) {
    }
}
