<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * `print_r()` and `var_dump()` read an object through its `__debugInfo()`, so
 * a secret a class masks there must reach neither dump, while every other
 * property still shows unmasked.
 */
trait SecretDumpAssertions
{
    /**
     * @param list<string> $secrets
     * @param list<string> $masked  the properties a dump shows as a `SensitiveParameterValue`
     */
    private static function assertDumpsMask(object $subject, array $secrets, array $masked): void
    {
        $printed = print_r($subject, true);
        ob_start();
        var_dump($subject);
        $dumped = (string) ob_get_clean();

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $printed);
            self::assertStringNotContainsString($secret, $dumped);
        }
        $labels = [];
        foreach (array_keys((array) $subject) as $key) {
            $parts = explode("\0", (string) $key);
            $label = match (\count($parts)) {
                1 => $parts[0],
                default => '*' === $parts[1] ? $parts[2].':protected' : $parts[2].':'.$parts[1].':private',
            };
            $labels[] = $label;
            $shownMasked = str_contains($printed, '['.$label.'] => SensitiveParameterValue Object');
            self::assertStringContainsString('['.$label.'] => ', $printed);
            self::assertSame(\in_array(explode(':', $label)[0], $masked, true), $shownMasked, $label);
        }
        foreach ($masked as $property) {
            self::assertNotSame([], preg_grep('/^'.preg_quote($property, '/').'(:|$)/', $labels), $property);
        }
    }

    /**
     * Symfony's VarDumper honours `__debugInfo()` only for a property that is
     * not public, so this holds only for a secret kept in one.
     *
     * @param list<string> $secrets
     */
    private static function assertVarDumperHides(object $subject, array $secrets): void
    {
        $dumped = self::varDumped($subject);

        self::assertStringContainsString('SensitiveParameterValue', $dumped);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $dumped);
        }
    }

    private static function varDumped(object $subject): string
    {
        return (string) (new CliDumper())->dump((new VarCloner())->cloneVar($subject), true);
    }
}
