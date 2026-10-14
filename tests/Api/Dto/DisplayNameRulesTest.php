<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\CreateChannelRequest;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisplayNameRulesTest extends TestCase
{
    private static function character(int $codePoint): string
    {
        if ($codePoint < 0x80) {
            return \chr($codePoint);
        }

        return $codePoint <= 0xFFFF
            ? (string) json_decode(\sprintf('"\\u%04X"', $codePoint))
            : html_entity_decode(\sprintf('&#x%X;', $codePoint), \ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacters(): iterable
    {
        $codePoints = [
            0x0000, 0x0009, 0x000A, 0x000D, 0x001F,
            0x007F, 0x0085, 0x009F,
            0x2028, 0x2029,
            0x202A, 0x202D, 0x202E,
            0x2066, 0x2067, 0x2069,
        ];
        foreach ($codePoints as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [self::character($codePoint)];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedNeighbours(): iterable
    {
        foreach ([0x0020, 0x007E, 0x00A0, 0x2027, 0x202F, 0x2065, 0x206A, 0x200B, 0x1F600] as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [self::character($codePoint)];
        }
    }

    #[DataProvider('forbiddenCharacters')]
    public function test_monitor_name_refuses_a_forbidden_character(string $character): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest('Night'.$character.'ly', ScheduleKind::Cron, '0 2 * * *');
    }

    #[DataProvider('forbiddenCharacters')]
    public function test_update_name_refuses_a_forbidden_character(string $character): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpdateMonitorRequest(name: 'Night'.$character.'ly');
    }

    #[DataProvider('forbiddenCharacters')]
    public function test_channel_label_refuses_a_forbidden_character(string $character): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CreateChannelRequest::email('Ops'.$character.'box', 'me@example.test');
    }

    #[DataProvider('allowedNeighbours')]
    public function test_characters_next_to_the_forbidden_ranges_are_accepted(string $character): void
    {
        $name = 'Night'.$character.'ly';

        self::assertSame($name, (new CreateMonitorRequest($name, ScheduleKind::Cron, '0 2 * * *'))->name);
        self::assertSame($name, (new UpdateMonitorRequest(name: $name))->name);
        self::assertSame($name, CreateChannelRequest::email($name, 'me@example.test')->label);
    }

    public function test_a_trailing_line_break_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest("Nightly\n", ScheduleKind::Cron, '0 2 * * *');
    }

    public function test_the_refusal_names_the_code_point_and_never_echoes_the_character(): void
    {
        try {
            new CreateMonitorRequest("Night\u{202E}ly", ScheduleKind::Cron, '0 2 * * *');
            self::fail('A direction override must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('U+202E', $e->getMessage());
            self::assertStringNotContainsString("\u{202E}", $e->getMessage());
        }
    }

    #[DataProvider('forbiddenCharacters')]
    public function test_the_refusal_names_every_forbidden_code_point(string $character): void
    {
        $expected = \sprintf('U+%04X', mb_ord($character, 'UTF-8'));

        try {
            CreateChannelRequest::email('Ops'.$character.'box', 'me@example.test');
            self::fail('A forbidden character must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function test_a_name_of_only_line_breaks_is_reported_as_holding_one(): void
    {
        try {
            new CreateMonitorRequest("\n\n", ScheduleKind::Cron, '0 2 * * *');
            self::fail('A line break must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('U+000A', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function monitorNameLengths(): iterable
    {
        yield 'one character' => ['a', false];
        yield 'two characters' => ['ab', true];
        yield 'two emoji are two characters' => ["\u{1F600}\u{1F600}", true];
        yield '120 characters' => [str_repeat('a', 120), true];
        yield '120 emoji' => [str_repeat("\u{1F600}", 120), true];
        yield '121 characters' => [str_repeat('a', 121), false];
        yield '121 emoji' => [str_repeat("\u{1F600}", 121), false];
        yield 'one emoji' => ["\u{1F600}", false];
    }

    #[DataProvider('monitorNameLengths')]
    public function test_monitor_name_length_is_counted_in_characters(string $name, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $create = new CreateMonitorRequest($name, ScheduleKind::Cron, '0 2 * * *');
        $update = new UpdateMonitorRequest(name: $name);

        self::assertSame($name, $create->name);
        self::assertSame($name, $update->name);
    }

    #[DataProvider('monitorNameLengths')]
    public function test_update_name_length_is_checked_on_its_own(string $name, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        self::assertSame($name, (new UpdateMonitorRequest(name: $name))->name);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function channelLabelLengths(): iterable
    {
        yield 'one character' => ['a', false];
        yield 'two characters' => ['ab', true];
        yield '80 characters' => [str_repeat('a', 80), true];
        yield '80 emoji' => [str_repeat("\u{1F600}", 80), true];
        yield '81 characters' => [str_repeat('a', 81), false];
        yield '81 emoji' => [str_repeat("\u{1F600}", 81), false];
    }

    #[DataProvider('channelLabelLengths')]
    public function test_channel_label_length_is_counted_in_characters(string $label, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        self::assertSame($label, CreateChannelRequest::email($label, 'me@example.test')->label);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedNames(): iterable
    {
        yield 'leading space under the minimum' => [' a'];
        yield 'trailing space under the minimum' => ['a '];
        yield 'trailing space over the maximum' => [str_repeat('a', 120).' '];
    }

    #[DataProvider('paddedNames')]
    public function test_the_minimum_counts_the_trimmed_value_and_the_maximum_the_value_as_sent(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest($name, ScheduleKind::Cron, '0 2 * * *');
    }

    public function test_padding_inside_the_bounds_is_accepted(): void
    {
        $name = ' '.str_repeat('a', 118).' ';

        self::assertSame($name, (new CreateMonitorRequest($name, ScheduleKind::Cron, '0 2 * * *'))->name);
    }

    public function test_an_omitted_update_name_is_not_checked(): void
    {
        self::assertNull((new UpdateMonitorRequest(graceSeconds: 30))->name);
    }

    public function test_the_blank_refusals_keep_their_messages(): void
    {
        try {
            new CreateMonitorRequest('   ', ScheduleKind::Cron, '0 2 * * *');
            self::fail('Blank must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Monitor name must be a non-empty string.', $e->getMessage());
        }
        try {
            new UpdateMonitorRequest(name: '   ');
            self::fail('Blank must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Monitor name, when provided, must be a non-empty string.', $e->getMessage());
        }
        try {
            CreateChannelRequest::email('   ', 'me@example.test');
            self::fail('Blank must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Channel label must be a non-empty string.', $e->getMessage());
        }
    }

    public function test_a_name_that_is_not_valid_utf8_is_left_to_the_transport(): void
    {
        $name = "Night\xC3\x28ly";

        self::assertSame($name, (new CreateMonitorRequest($name, ScheduleKind::Cron, '0 2 * * *'))->name);
    }
}
