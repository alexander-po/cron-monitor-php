<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\DependencyInjection;

use CronMonitor\Client\Configuration as ClientConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cron_monitor');
        // `getRootNode()` is declared as `NodeDefinition` on Symfony's
        // ConfigurationInterface but returns the array-shaped variant in
        // practice (the root of every tree builder is an array node).
        // The narrow assertion satisfies PHPStan on PHP 8.1 where the
        // composer resolver pins an older Symfony version without the
        // narrowed phpdoc return type.
        $rootNode = $treeBuilder->getRootNode();
        \assert($rootNode instanceof ArrayNodeDefinition);

        $rootNode
            ->children()
                ->scalarNode('endpoint')
                    ->defaultValue(ClientConfiguration::DEFAULT_ENDPOINT)
                    ->info('Cron-monitor base URL. Defaults to the SaaS install.')
                ->end()
                ->floatNode('timeout_seconds')
                    ->defaultValue(ClientConfiguration::DEFAULT_TIMEOUT_SECONDS)
                    ->min(0.1)
                    ->info('Per-request timeout. Keep low so a network blip does not extend job duration.')
                ->end()
                ->integerNode('retries')
                    ->defaultValue(ClientConfiguration::DEFAULT_RETRIES)
                    ->min(0)
                    ->max(5)
                    ->info('Per-ping retry budget. Pings are idempotent server-side.')
                ->end()
                ->scalarNode('api_key')
                    ->defaultNull()
                    ->info('Optional account-level API key, env(CRON_MONITOR_API_KEY).')
                ->end()
                ->booleanNode('allow_insecure_endpoint')
                    ->defaultFalse()
                    ->info('Required when pointing at a self-hosted HTTP-only endpoint.')
                ->end()
                ->arrayNode('messages')
                    ->info('Map of FQCN => monitor UUID. Each Messenger handler that processes one of the listed messages will be wrapped in start/success/fail pings.')
                    // Disable Symfony's default dash→underscore key
                    // normalization. FQCNs never contain dashes today, but
                    // PSR-4 namespaces with embedded numerics or future
                    // composer naming changes could; keep the key byte-for-
                    // byte equal to what the consumer wrote, since the
                    // middleware compares it with `$message::class`.
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('class')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('commands')
                    ->info('Map of console command name (e.g. "app:reports:nightly", "app:short-links:purge-disabled") => monitor UUID. Each invocation is wrapped in start/success/fail pings via a kernel event subscriber.')
                    // Symfony console command names commonly contain
                    // dashes (`app:short-links:purge-disabled`,
                    // `cache:clear`-style colons are fine, dashes are not).
                    // Without disabling key normalization the bundle silently
                    // rewrites them to underscores at compile time and the
                    // subscriber's `$commandMap[$commandName]` lookup never
                    // matches — pings stop firing with no error or warning.
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
