<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\DependencyInjection;

use CronMonitor\Bridge\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Regression tests for the bundle's config tree. Pins behaviour that escaped
 * earlier review — in particular that map keys (command names, message
 * FQCNs) survive Symfony's config processor byte-for-byte rather than being
 * silently rewritten by the default dash→underscore key normalization.
 */
final class ConfigurationTest extends TestCase
{
    public function test_command_names_with_hyphens_are_preserved_byte_for_byte(): void
    {
        $input = [
            'commands' => [
                'app:short-links:purge-disabled' => '5ae7082c-4f16-11f1-9578-3a3d746059db',
                'app:reports:nightly' => '11111111-1111-1111-1111-111111111111',
            ],
        ];

        $processed = $this->process($input);

        self::assertSame(
            [
                'app:short-links:purge-disabled' => '5ae7082c-4f16-11f1-9578-3a3d746059db',
                'app:reports:nightly' => '11111111-1111-1111-1111-111111111111',
            ],
            $processed['commands'],
            'Symfony command names commonly contain hyphens; the bundle must not '.
            'rewrite them to underscores or the kernel subscriber will never '.
            'match `$commandName` against its map and pings stop firing silently.',
        );
    }

    public function test_message_fqcns_are_preserved_byte_for_byte(): void
    {
        $input = [
            'messages' => [
                'App\\Message\\Nightly_Report' => '22222222-2222-2222-2222-222222222222',
            ],
        ];

        $processed = $this->process($input);

        self::assertSame(
            ['App\\Message\\Nightly_Report' => '22222222-2222-2222-2222-222222222222'],
            $processed['messages'],
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function process(array $input): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$input]);
    }
}
