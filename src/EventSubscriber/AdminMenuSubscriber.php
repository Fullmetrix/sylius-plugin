<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.menu.admin.main' => 'addMenu',
        ];
    }

    public function addMenu(mixed $event): void
    {
        if (!\is_object($event) || !method_exists($event, 'getMenu')) {
            return;
        }

        try {
            $menu = $event->getMenu();
            $parent = $menu->getChild('marketing') ?? $menu;
            $child = $parent->addChild('fullmetrix', [
                'route' => 'fullmetrix_admin_connection',
            ]);
            $child->setLabel('Fullmetrix');
            $child->setLabelAttribute('icon', 'tabler:chart-bar');
        } catch (\Throwable) {
        }
    }
}
