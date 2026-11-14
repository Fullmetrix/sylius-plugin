<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('fullmetrix');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('api_base')
                    ->defaultValue('https://fullmetrix.com/api/plugin')
                ->end()
                ->scalarNode('webhook_endpoint')
                    ->defaultValue('https://fullmetrix.com/api/webhooks/ecommerce')
                ->end()
                ->scalarNode('events_endpoint')
                    ->defaultValue('https://fullmetrix.com/api/webhooks/events')
                ->end()
                ->scalarNode('consent_endpoint')
                    ->defaultValue('https://fullmetrix.com/api/checkout-consent')
                ->end()
                ->scalarNode('tracker_origin')
                    ->defaultValue('https://fullmetrix.com')
                ->end()
                ->scalarNode('tracker_local_directory')
                    ->defaultValue('%kernel.project_dir%/public/media/kvo')
                ->end()
                ->scalarNode('tracker_local_legacy_directory')
                    ->defaultValue('%kernel.project_dir%/public/media/fullmetrix')
                ->end()
                ->scalarNode('tracker_local_public_path')
                    ->defaultValue('/media/kvo')
                ->end()
                ->scalarNode('tracker_local_base_url')
                    ->defaultNull()
                ->end()
                ->integerNode('connect_timeout_ms')
                    ->defaultValue(300)
                ->end()
                ->integerNode('total_timeout_ms')
                    ->defaultValue(800)
                ->end()
                ->booleanNode('signature_v1')
                    ->defaultFalse()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
