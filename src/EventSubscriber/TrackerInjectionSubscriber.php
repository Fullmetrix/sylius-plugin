<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TrackerInjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly string $trackerOrigin,
        private readonly string $pluginVersion,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->config->isActive()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/fullmetrix/api') || str_starts_with($path, '/_')) {
            return;
        }

        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $content = $response->getContent();
        if (false === $content || !str_contains($content, '</head>')) {
            return;
        }

        $code = $this->config->getConnectionCode();
        if (null === $code) {
            return;
        }

        $pluginConfig = $this->config->get(ConfigStore::KEY_PLUGIN_CONFIG);
        if (\is_array($pluginConfig) && isset($pluginConfig['trackerEnabled']) && false === $pluginConfig['trackerEnabled']) {
            return;
        }

        $version = $this->pluginVersion . '.' . floor(time() / 300);
        $tag = sprintf(
            '<script async src="%s/t.js?ver=%s" data-key="%s"></script>',
            htmlspecialchars($this->trackerOrigin, \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($version, \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, \ENT_QUOTES, 'UTF-8'),
        );

        $injected = preg_replace('/<\/head>/i', $tag . '</head>', $content, 1);
        if (\is_string($injected)) {
            $response->setContent($injected);
        }
    }
}
