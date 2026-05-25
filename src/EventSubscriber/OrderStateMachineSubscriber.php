<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\WebhookQueue;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class OrderStateMachineSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly WebhookQueue $queue)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.order.post_create' => 'onOrderEvent',
            'sylius.order.post_complete' => 'onOrderEvent',
            'sylius.order.post_pay' => 'onOrderEvent',
            'sylius.order.post_cancel' => 'onOrderEvent',
            'sylius.order.post_refund' => 'onOrderRefund',
            'sylius.order_payment.post_complete' => 'onOrderEvent',
            'sylius.order_payment.post_refund' => 'onOrderRefund',
            'sylius.order_shipping.post_ship' => 'onOrderEvent',
        ];
    }

    public function onOrderEvent(Event $event): void
    {
        $order = $this->resolveOrder($event);
        if (null === $order || null === $order->getId()) {
            return;
        }

        $this->queue->enqueue(WebhookQueue::TYPE_ORDER, $order->getId());
    }

    public function onOrderRefund(Event $event): void
    {
        $order = $this->resolveOrder($event);
        if (null === $order || null === $order->getId()) {
            return;
        }

        $this->queue->enqueue(WebhookQueue::TYPE_REFUND, $order->getId());
        $this->queue->enqueue(WebhookQueue::TYPE_ORDER, $order->getId());
    }

    private function resolveOrder(Event $event): ?OrderInterface
    {
        if (method_exists($event, 'getSubject')) {
            $subject = $event->getSubject();
            if ($subject instanceof OrderInterface) {
                return $subject;
            }
            if (\is_object($subject) && method_exists($subject, 'getOrder')) {
                $maybeOrder = $subject->getOrder();
                if ($maybeOrder instanceof OrderInterface) {
                    return $maybeOrder;
                }
            }
        }

        return null;
    }
}
