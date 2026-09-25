<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin;

use Fullmetrix\SyliusPlugin\DependencyInjection\Compiler\MigrationOrderPass;
use Fullmetrix\SyliusPlugin\DependencyInjection\FullmetrixExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class FullmetrixPlugin extends Bundle
{
    public const VERSION = '1.5.1';

    private ?FullmetrixExtension $fullmetrixExtension = null;

    public function getPath(): string
    {
        return __DIR__;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MigrationOrderPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return $this->fullmetrixExtension ??= new FullmetrixExtension();
    }
}
