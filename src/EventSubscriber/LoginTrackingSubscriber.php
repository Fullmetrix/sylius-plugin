<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\TrackingQueue;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\AuthenticationEvents;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

final class LoginTrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TrackingQueue $tracking)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
            AuthenticationEvents::AUTHENTICATION_SUCCESS => 'onAuthSuccess',
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $this->identify($event->getAuthenticationToken()->getUser());
    }

    public function onAuthSuccess(AuthenticationSuccessEvent $event): void
    {
        $this->identify($event->getAuthenticationToken()->getUser());
    }

    private function identify(mixed $user): void
    {
        if (!\is_object($user) || !method_exists($user, 'getCustomer')) {
            return;
        }
        $customer = $user->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            return;
        }

        $address = $customer->getDefaultAddress();
        $this->tracking->enqueue(TrackingQueue::EVENT_IDENTIFY, [], [
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhoneNumber(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'customer_id' => $customer->getId(),
            'country_code' => $address?->getCountryCode(),
            'identified_at' => (int) round(microtime(true) * 1000),
        ]);
    }
}
