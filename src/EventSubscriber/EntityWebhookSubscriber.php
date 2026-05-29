<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Fullmetrix\SyliusPlugin\Service\WebhookQueue;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class EntityWebhookSubscriber
{
    public function __construct(private readonly WebhookQueue $queue)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    private function handle(object $entity): void
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if (null === $id) {
            return;
        }

        $type = match (true) {
            $entity instanceof OrderInterface => WebhookQueue::TYPE_ORDER,
            $entity instanceof CustomerInterface => WebhookQueue::TYPE_CUSTOMER,
            $entity instanceof ProductInterface => WebhookQueue::TYPE_PRODUCT,
            $entity instanceof TaxonInterface => WebhookQueue::TYPE_CATEGORY,
            $entity instanceof PromotionInterface => WebhookQueue::TYPE_COUPON,
            default => null,
        };

        if (null === $type) {
            return;
        }

        $this->queue->enqueue($type, $id);
    }
}
