<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class GiftCouponSubscriber implements EventSubscriberInterface
{
    public const GIFT_FLAG_KEY = '_fullmetrix_gift';

    public function __construct(
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.order.pre_complete' => 'onOrderComplete',
            'sylius.order.pre_pay' => 'onOrderComplete',
        ];
    }

    public function onOrderComplete(Event $event): void
    {
        if (!method_exists($event, 'getSubject')) {
            return;
        }
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getItems() as $item) {
            if (!$this->isGiftItem($item)) {
                continue;
            }
            $item->setUnitPrice(0);
            if (1 !== $item->getQuantity()) {
                $this->quantityModifier->modify($item, 1);
            }
        }
    }

    private function isGiftItem(OrderItemInterface $item): bool
    {
        foreach ($item->getAdjustments() as $adjustment) {
            $details = $adjustment->getDetails();
            if (isset($details[self::GIFT_FLAG_KEY]) && true === $details[self::GIFT_FLAG_KEY]) {
                return true;
            }
        }

        return false;
    }
}
