<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FullmetrixExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'FullmetrixPlugin' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => \dirname(__DIR__) . '/Entity',
                            'prefix' => 'Fullmetrix\\SyliusPlugin\\Entity',
                            'alias' => 'FullmetrixPlugin',
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('sylius_twig_hooks')) {
            $container->prependExtensionConfig('sylius_twig_hooks', [
                'hooks' => [
                    'sylius_admin.fullmetrix.index.content' => [
                        'flashes' => ['enabled' => false],
                        'header' => ['enabled' => false],
                        'grid' => ['enabled' => false],
                        'footer' => ['enabled' => false],
                        'fullmetrix' => [
                            'template' => '@FullmetrixPlugin/admin/_content.html.twig',
                            'priority' => 0,
                        ],
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('fullmetrix.api_base', $config['api_base']);
        $container->setParameter('fullmetrix.webhook_endpoint', $config['webhook_endpoint']);
        $container->setParameter('fullmetrix.events_endpoint', $config['events_endpoint']);
        $container->setParameter('fullmetrix.consent_endpoint', $config['consent_endpoint']);
        $container->setParameter('fullmetrix.tracker_origin', $config['tracker_origin']);
        $container->setParameter('fullmetrix.connect_timeout_ms', $config['connect_timeout_ms']);
        $container->setParameter('fullmetrix.total_timeout_ms', $config['total_timeout_ms']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(\dirname(__DIR__) . '/Resources/config'),
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'fullmetrix';
    }
}
