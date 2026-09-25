<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MigrationOrderPass implements CompilerPassInterface
{
    public const NAMESPACE = 'Fullmetrix\\SyliusPlugin\\Migrations';

    private const CONFIGURATION = 'doctrine.migrations.configuration';

    private const COMPARATOR = 'SyliusLabs\\DoctrineMigrationsExtraBundle\\Comparator\\TopologicalVersionComparator';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONFIGURATION)) {
            return;
        }
        if (
            $container->hasParameter('sylius_core.prepend_doctrine_migrations') &&
            !$container->getParameter('sylius_core.prepend_doctrine_migrations')
        ) {
            return;
        }

        $configuration = $container->getDefinition(self::CONFIGURATION);
        $namespaces = [];
        foreach ($configuration->getMethodCalls() as [$method, $arguments]) {
            if ('addMigrationsDirectory' === $method && isset($arguments[0]) && \is_string($arguments[0])) {
                $namespaces[] = $arguments[0];
            }
        }
        if (\in_array(self::NAMESPACE, $namespaces, true)) {
            return;
        }

        $configuration->addMethodCall('addMigrationsDirectory', [self::NAMESPACE, \dirname(__DIR__, 2) . '/Migrations']);

        if (!$container->hasDefinition(self::COMPARATOR)) {
            return;
        }

        $comparator = $container->getDefinition(self::COMPARATOR);
        $packages = $comparator->getArguments()[0] ?? [];
        if (!\is_array($packages) || \array_key_exists(self::NAMESPACE, $packages)) {
            return;
        }

        foreach ($namespaces as $namespace) {
            if (!\array_key_exists($namespace, $packages)) {
                $packages[$namespace] = [];
            }
        }
        $packages[self::NAMESPACE] = array_keys($packages);

        $comparator->setArgument(0, $packages);
    }
}
