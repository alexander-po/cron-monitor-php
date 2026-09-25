<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Skill;

use CronMonitor\Bridge\Laravel\CronMonitorServiceProvider;
use CronMonitor\Bridge\Symfony\DependencyInjection\CronMonitorExtension;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Holds the agent recipe to what the package registers: nothing else compiles
 * the commands and the frontmatter it names.
 */
final class AddCronheartSkillTest extends TestCase
{
    private const SKILL = __DIR__.'/../../skills/add-cronheart/SKILL.md';

    protected function tearDown(): void
    {
        Artisan::forgetBootstrappers();
        Container::setInstance(null);
    }

    public function test_frontmatter_names_the_skill_after_its_folder_with_a_one_line_description(): void
    {
        self::assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', self::skill(), $match), 'SKILL.md must open with a frontmatter block.');

        \assert(isset($match[1]));

        $fields = [];
        foreach (explode("\n", $match[1]) as $line) {
            self::assertSame(1, preg_match('/^([a-z_]+): (\S.*)$/', $line, $pair), \sprintf('Frontmatter line is not `key: value`: %s', $line));
            \assert(isset($pair[1], $pair[2]));
            $fields[$pair[1]] = $pair[2];
        }

        self::assertSame(['name', 'description'], array_keys($fields));
        self::assertSame(basename(\dirname(self::SKILL)), $fields['name']);
        self::assertStringNotContainsString(': ', $fields['description'], 'A plain YAML scalar cannot contain ": ".');
        self::assertStringNotContainsString(' #', $fields['description'], 'A plain YAML scalar cannot contain " #".');
    }

    public function test_every_bundle_command_the_recipe_names_is_registered_by_both_bridges(): void
    {
        preg_match_all('/\bcron-monitor:[a-z][a-z0-9-]*/', self::skill(), $match);
        $named = array_values(array_unique($match[0]));
        self::assertNotEmpty($named, 'The recipe names no bundle command, so the check would be vacuous.');

        $symfony = self::symfonyCommandNames();
        $laravel = self::laravelCommandNames();
        foreach ($named as $name) {
            self::assertContains($name, $symfony, \sprintf('%s is not a console command the Symfony bundle registers.', $name));
            self::assertContains($name, $laravel, \sprintf('%s is not an artisan command the Laravel provider registers.', $name));
        }
    }

    public function test_every_cli_subcommand_the_recipe_names_is_one_the_binary_accepts(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        preg_match_all('~vendor/bin/cron-monitor\s+([a-z]+)~', self::skill(), $match);
        $named = array_values(array_unique($match[1]));
        self::assertNotEmpty($named, 'The recipe names no CLI subcommand, so the check would be vacuous.');

        foreach ($named as $subcommand) {
            [$status, $stderr] = self::runCli([$subcommand]);

            self::assertSame(64, $status);
            self::assertStringStartsWith('signup' === $subcommand ? 'Missing email address.' : 'Missing monitor UUID.', $stderr, \sprintf('%s is not a subcommand vendor/bin/cron-monitor accepts.', $subcommand));
        }
    }

    public function test_the_signup_flags_the_recipe_passes_are_ones_the_binary_accepts(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        preg_match_all('~vendor/bin/cron-monitor\s+signup\s+\S+((?:\s+--[a-z-]+)+)~', self::skill(), $match);
        self::assertNotEmpty($match[1], 'The recipe runs no signup with flags, so the check would be vacuous.');
        $flags = array_values(array_unique(preg_split('/\s+/', trim(implode(' ', $match[1]))) ?: []));

        [$status, $stderr] = self::runCli(['signup', 'you@example.com', ...$flags, '--endpoint=http://127.0.0.1:9']);

        self::assertSame(64, $status);
        self::assertStringStartsWith('Configuration error: Refusing to sign up over plain HTTP endpoint', $stderr, 'the binary rejected a flag the recipe passes');
    }

    private static function skill(): string
    {
        $skill = file_get_contents(self::SKILL);
        self::assertIsString($skill);

        return $skill;
    }

    /**
     * @return list<string>
     */
    private static function symfonyCommandNames(): array
    {
        $container = new ContainerBuilder();
        (new CronMonitorExtension())->load([], $container);

        $names = [];
        foreach (array_keys($container->findTaggedServiceIds('console.command')) as $id) {
            $class = $container->getDefinition((string) $id)->getClass() ?? (string) $id;
            \assert(class_exists($class));
            foreach ((new \ReflectionClass($class))->getAttributes(AsCommand::class) as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function laravelCommandNames(): array
    {
        $container = new class extends Container {
            public function runningInConsole(): bool
            {
                return true;
            }

            public function configPath(string $path = ''): string
            {
                return sys_get_temp_dir().'/'.$path;
            }
        };
        Container::setInstance($container);

        // `boot()` only calls the two methods stubbed above plus inherited
        // `Container` methods; a call to a third Application method fails
        // this test loudly rather than silently.
        /** @var \Illuminate\Contracts\Foundation\Application $application */
        $application = $container;
        (new CronMonitorServiceProvider($application))->boot();

        $names = [];
        foreach ((new Artisan($container, new Dispatcher($container), 'test'))->all() as $command) {
            $names[] = (string) $command->getName();
        }

        return $names;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private static function runCli(array $arguments): array
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../../bin/cron-monitor', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr];
    }
}
