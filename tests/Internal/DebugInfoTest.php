<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Internal;

use CronMonitor\Internal\DebugInfo;
use CronMonitor\Tests\Support\SecretDumpAssertions;
use PHPUnit\Framework\TestCase;

final class DebugInfoTest extends TestCase
{
    use SecretDumpAssertions;

    public function test_each_named_property_is_masked_under_the_key_php_uses_for_it(): void
    {
        $dump = DebugInfo::mask(new SecretHolder(), 'shown', 'publicSecret', 'protectedSecret', 'privateSecret');

        self::assertSame(
            ['shown', 'publicSecret', "\0*\0protectedSecret", "\0".SecretHolder::class."\0privateSecret", "\0".SecretHolder::class."\0absent"],
            array_keys($dump),
        );
        foreach (\array_slice($dump, 0, 4) as $key => $value) {
            self::assertInstanceOf(\SensitiveParameterValue::class, $value, $key);
        }
    }

    public function test_a_property_left_unnamed_or_null_shows_as_it_is(): void
    {
        $dump = DebugInfo::mask(new SecretHolder(), 'absent');

        self::assertSame('label', $dump['shown']);
        self::assertSame('fake-public-secret', $dump['publicSecret']);
        self::assertNull($dump["\0".SecretHolder::class."\0absent"]);
    }

    public function test_the_object_keeps_its_values(): void
    {
        $holder = new SecretHolder();
        DebugInfo::mask($holder, 'privateSecret', 'absent');

        self::assertSame(['fake-private-secret', null], $holder->privateValues());
    }

    public function test_a_name_the_object_does_not_hold_is_skipped_rather_than_thrown_on(): void
    {
        $dump = DebugInfo::mask(new UninitialisedHolder(), 'secret', 'noSuchProperty');

        self::assertSame([], $dump);
    }

    public function test_dumps_mask_each_property_and_var_dumper_masks_the_non_public_ones(): void
    {
        $holder = new SecretHolder();

        self::assertDumpsMask($holder, ['fake-public-secret', 'fake-protected-secret', 'fake-private-secret'], ['publicSecret', 'protectedSecret', 'privateSecret']);
        self::assertVarDumperHides($holder, ['fake-protected-secret', 'fake-private-secret']);
        self::assertStringContainsString('fake-public-secret', self::varDumped($holder));
    }

    public function test_a_private_property_a_parent_class_declares_is_masked_on_a_subclass(): void
    {
        $dump = (new SecretHolderChild())->__debugInfo();

        self::assertInstanceOf(\SensitiveParameterValue::class, $dump["\0".SecretHolder::class."\0privateSecret"]);
        self::assertVarDumperHides(new SecretHolderChild(), ['fake-protected-secret', 'fake-private-secret']);
    }
}

class SecretHolder
{
    public string $shown = 'label';

    public string $publicSecret = 'fake-public-secret';

    protected string $protectedSecret = 'fake-protected-secret';

    private string $privateSecret = 'fake-private-secret';

    private ?string $absent = null;

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return DebugInfo::mask($this, 'publicSecret', 'protectedSecret', 'privateSecret', 'absent');
    }

    /**
     * @return list<?string>
     */
    public function privateValues(): array
    {
        return [$this->privateSecret, $this->absent];
    }
}

final class UninitialisedHolder
{
    public string $secret;
}

final class SecretHolderChild extends SecretHolder
{
}
