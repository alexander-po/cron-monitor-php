<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

/**
 * `print_r()` and `var_dump()` read an object through its `__debugInfo()`, so
 * a secret a class masks there must reach neither dump, while every other
 * property still shows.
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
        foreach (array_keys(get_object_vars($subject)) as $property) {
            self::assertStringContainsString('['.$property.'] => ', $printed);
        }
        foreach ($masked as $property) {
            self::assertStringContainsString('['.$property.'] => SensitiveParameterValue Object', $printed);
        }
    }
}
