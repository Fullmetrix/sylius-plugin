<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\CheckoutConsentSender;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\Event;

final class CheckoutConsentSubscriber implements EventSubscriberInterface
{
    public const POST_CONSENT_KEY = '_fullmetrix_consent';
    public const POST_CHANNELS_KEY = '_fullmetrix_consent_channels';

    public function __construct(
        private readonly CheckoutConsentSender $sender,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.order.pre_complete' => 'onOrderComplete',
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

        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return;
        }

        $consentValue = $request->request->get(self::POST_CONSENT_KEY);
        if (null === $consentValue) {
            return;
        }

        $consent = \in_array((string) $consentValue, ['1', 'yes', 'true', 'on'], true);
        $channelsRaw = $request->request->all(self::POST_CHANNELS_KEY);
        $channels = [];
        foreach ((array) $channelsRaw as $channel) {
            if (\is_string($channel) && '' !== $channel) {
                $channels[] = $channel;
            }
        }
        if (empty($channels)) {
            $channels = ['email'];
        }

        $customer = $order->getCustomer();
        $email = $customer?->getEmail() ?: $order->getEmail();
        $phone = $customer?->getPhoneNumber() ?: $order->getBillingAddress()?->getPhoneNumber();

        $this->sender->send($email, $phone, $consent, $channels, $request->getUri());
    }
}
