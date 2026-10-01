<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SyliusServicePass implements CompilerPassInterface
{
    public const SERVICES = [
        'fullmetrix.sylius.order_modifier' => ['sylius.modifier.order', 'sylius.order_modifier'],
        'fullmetrix.sylius.promotion_coupon_eligibility_checker' => [
            'sylius.checker.promotion_coupon_eligibility',
            'sylius.promotion_coupon_eligibility_checker',
        ],
        'fullmetrix.sylius.promotion_eligibility_checker' => [
            'sylius.checker.promotion_eligibility',
            'sylius.promotion_eligibility_checker',
        ],
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::SERVICES as $alias => $candidates) {
            foreach ($candidates as $candidate) {
                if ($container->has($candidate)) {
                    $container->setAlias($alias, $candidate);

                    break;
                }
            }
        }
    }
}
