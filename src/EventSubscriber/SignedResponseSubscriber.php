<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\FullmetrixPlugin;
use Fullmetrix\SyliusPlugin\Security\HmacRequestVerifier;
use Fullmetrix\SyliusPlugin\Security\Signature;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\HmacSigner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SignedResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly Signature $signature,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with((string) $request->attributes->get('_route'), 'fullmetrix_api_')) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set(HmacSigner::HEADER_PLUGIN_VERSION, FullmetrixPlugin::VERSION);
        $nonce = $request->attributes->get(HmacRequestVerifier::NONCE_ATTRIBUTE);
        $secret = $this->config->getConnectionSecret();
        if (!\is_string($nonce) || null === $secret || $response instanceof StreamedResponse) {
            return;
        }
        $body = $response->getContent();
        if (false === $body) {
            return;
        }
        foreach ($this->signature->responseHeaders($secret, $nonce, $body) as $name => $value) {
            $response->headers->set($name, $value);
        }
    }
}
