<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\CartSerializer;
use Fullmetrix\SyliusPlugin\Service\TrackingQueue;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class CartTrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TrackingQueue $tracking,
        private readonly CartSerializer $cartSerializer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.order_item.post_add' => 'onItemAdded',
            'sylius.order_item.post_remove' => 'onItemRemoved',
            'sylius.cart.post_change' => 'onCartChange',
        ];
    }

    public function onItemAdded(Event $event): void
    {
        $item = $this->resolveSubject($event, OrderItemInterface::class);
        if (!$item instanceof OrderItemInterface) {
            return;
        }
        $order = $item->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $variant = $item->getVariant();
        $product = $variant?->getProduct();

        $this->tracking->enqueue(TrackingQueue::EVENT_ADDED_TO_CART, [
            'added_item' => [
                'product_id' => $product?->getId(),
                'variation_id' => $variant?->getId(),
                'name' => $item->getProductName(),
                'quantity' => $item->getQuantity(),
                'price' => number_format($item->getUnitPrice() / 100, 2, '.', ''),
                'sku' => $variant?->getCode(),
            ],
            'cart' => $this->cartSerializer->serialize($order),
            'source' => 'server',
        ]);
    }

    public function onItemRemoved(Event $event): void
    {
        $item = $this->resolveSubject($event, OrderItemInterface::class);
        if (!$item instanceof OrderItemInterface) {
            return;
        }
        $order = $item->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $variant = $item->getVariant();
        $product = $variant?->getProduct();

        $this->tracking->enqueue(TrackingQueue::EVENT_REMOVED_FROM_CART, [
            'removed_item' => [
                'product_id' => $product?->getId(),
                'variation_id' => $variant?->getId(),
                'name' => $item->getProductName(),
                'sku' => $variant?->getCode(),
            ],
            'cart' => $this->cartSerializer->serialize($order),
            'source' => 'server',
        ]);
    }

    public function onCartChange(Event $event): void
    {
        $order = $this->resolveSubject($event, OrderInterface::class);
        if (!$order instanceof OrderInterface) {
            return;
        }

        $this->tracking->enqueue(
            TrackingQueue::EVENT_CART_UPDATED,
            ['cart' => $this->cartSerializer->serialize($order), 'source' => 'server'],
            null,
            true,
        );
    }

    private function resolveSubject(Event $event, string $expectedClass): ?object
    {
        if (!method_exists($event, 'getSubject')) {
            return null;
        }
        $subject = $event->getSubject();

        return $subject instanceof $expectedClass ? $subject : null;
    }
}
