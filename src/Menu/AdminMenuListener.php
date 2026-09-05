<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addFullmetrixItem(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('fullmetrix', ['route' => 'fullmetrix_admin_connection'])
            ->setLabel('Fullmetrix')
            ->setLabelAttribute('icon', 'tabler:chart-bar')
        ;
    }
}
