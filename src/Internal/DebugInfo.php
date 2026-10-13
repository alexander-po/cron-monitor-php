<?php

declare(strict_types=1);

namespace CronMonitor\Internal;

/**
 * The body of every `__debugInfo()` that hides a credential: the object's
 * properties as `print_r()` and `var_dump()` would list them, with each named
 * one shown the way PHP shows a `#[\SensitiveParameter]` argument, whichever
 * class declares it.
 *
 * Each property stays under the key PHP itself uses for it, mangled when it is
 * not public, because Symfony's VarDumper replaces a property only when
 * `__debugInfo()` returns that key and otherwise prints the value beside the
 * mask. A public property it cannot replace at all. A null property stays
 * null, and a name the object does not hold, or holds uninitialised, is
 * skipped: this runs inside a dump and must not throw.
 *
 * @internal not part of the SDK's public, SemVer-stable surface
 */
final class DebugInfo
{
    /**
     * @return array<string, mixed>
     */
    public static function mask(object $object, string ...$properties): array
    {
        $dump = (array) $object;
        foreach ($dump as $key => $value) {
            $at = strrpos((string) $key, "\0");
            $name = false === $at ? (string) $key : substr((string) $key, $at + 1);
            if (null !== $value && \in_array($name, $properties, true)) {
                $dump[$key] = new \SensitiveParameterValue($value);
            }
        }

        return $dump;
    }
}
