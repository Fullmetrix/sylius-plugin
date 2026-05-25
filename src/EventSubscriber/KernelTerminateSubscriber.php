<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\HttpClient;
use Fullmetrix\SyliusPlugin\Service\TrackingQueue;
use Fullmetrix\SyliusPlugin\Service\WebhookQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class KernelTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly TrackingQueue $trackingQueue,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onTerminate(): void
    {
        HttpClient::finishRequestEarly();

        try {
            $this->webhookQueue->flush();
        } catch (\Throwable) {
        }

        try {
            $this->trackingQueue->flush();
        } catch (\Throwable) {
        }
    }
}
