<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\LocalTracker;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TrackerInjectionSubscriber implements EventSubscriberInterface
{
    public const THANK_YOU_ROUTE = 'sylius_shop_order_thank_you';

    public const ORDER_ATTRIBUTE = '_fullmetrix_order_id';

    private const CART_SESSION_PREFIX = '_sylius.cart.';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly string $trackerOrigin,
        private readonly string $pluginVersion,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?string $orderClass = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        try {
            $request = $event->getRequest();
            if (!$event->isMainRequest() || self::THANK_YOU_ROUTE !== $request->attributes->get('_route') || !$request->hasSession()) {
                return;
            }
            $orderId = self::positiveId($request->getSession()->get('sylius_order_id'));
            if (null !== $orderId) {
                $request->attributes->set(self::ORDER_ATTRIBUTE, $orderId);
            }
        } catch (\Throwable) {
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        try {
            $this->inject($event);
        } catch (\Throwable) {
        }
    }

    private function inject(ResponseEvent $event): void
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
        if (str_starts_with((string) $request->attributes->get('_route', ''), 'sylius_admin')) {
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
        if (\is_array($pluginConfig) && isset($pluginConfig['data']['trackerEnabled']) && false === $pluginConfig['data']['trackerEnabled']) {
            return;
        }

        $version = $this->pluginVersion . '.' . floor(time() / 300);
        $remote = $this->trackerOrigin . '/t.js?ver=' . $version;
        $privatePage = !$response->headers->hasCacheControlDirective('public');
        $context = $privatePage ? $this->cartAttribute($request) : '';
        $serverAttributes = LocalTracker::tagAttributes($this->config->get(ConfigStore::KEY_TAG_ATTRIBUTES));
        if (LocalTracker::keeper($this->config->get(ConfigStore::KEY_KEEPER))['enabled']) {
            $serverAttributes = ['data-fm-keeper' => $request->getBaseUrl() . LocalTracker::KEEPER_PATH] + $serverAttributes;
        }
        $extra = self::attributes($serverAttributes);
        $local = LocalTracker::usable($this->config->get(ConfigStore::KEY_LOCAL_TRACKER), time());

        if (null === $local) {
            $tag = sprintf(
                '<script async src="%s/t.js?ver=%s" data-key="%s"%s%s></script>',
                htmlspecialchars($this->trackerOrigin, \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($version, \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($code, \ENT_QUOTES, 'UTF-8'),
                $extra,
                $context,
            );
        } else {
            $tag = sprintf(
                '<script async src="%s" data-key="%s" data-platform="sylius" data-fm-origin="%s"%s%s onerror="%s"></script>',
                htmlspecialchars($request->getBasePath() . $local['path'], \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($code, \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($local['origin'], \ENT_QUOTES, 'UTF-8'),
                $extra,
                $context,
                htmlspecialchars(self::fallbackScript($remote), \ENT_QUOTES, 'UTF-8'),
            );
        }

        $injected = preg_replace('/<\/head>/i', $tag . '</head>', $content, 1);
        if (!\is_string($injected)) {
            return;
        }

        $marker = $privatePage ? $this->orderMarker($request) : '';
        $bodyEnd = '' === $marker ? false : strripos($injected, '</body>');
        if (false !== $bodyEnd) {
            $injected = substr($injected, 0, $bodyEnd) . $marker . substr($injected, $bodyEnd);
        }

        $response->setContent($injected);
    }

    public static function fallbackScript(string $remote): string
    {
        $url = json_encode($remote, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return 'this.onerror=null;var o=this,s=document.createElement(\'script\'),i,a;s.src=' . $url
            . ';s.async=true;for(i=0;i<o.attributes.length;i++){a=o.attributes[i];if(a.name.indexOf(\'data-\')===0)s.setAttribute(a.name,a.value)}document.head.appendChild(s)';
    }

    private function cartAttribute(Request $request): string
    {
        try {
            $cartId = $this->cartId($request);
        } catch (\Throwable) {
            return '';
        }

        return null === $cartId ? '' : self::attributes(['data-fm-cart-id' => (string) $cartId]);
    }

    private function orderMarker(Request $request): string
    {
        try {
            $order = $this->order($request);
            if (null === $order) {
                return '';
            }

            return '<div id="fullmetrix-order" hidden' . self::attributes([
                'data-fm-order-id' => (string) $order->getId(),
                'data-fm-order-number' => (string) $order->getNumber(),
                'data-fm-order-total' => number_format($order->getTotal() / 100, 2, '.', ''),
                'data-fm-order-currency' => (string) $order->getCurrencyCode(),
            ]) . '></div>';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string, string> $attributes */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, \ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html;
    }

    private function cartId(Request $request): ?int
    {
        if (!$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return null;
        }

        $found = [];
        foreach ($session->all() as $key => $value) {
            if (\is_string($key) && str_starts_with($key, self::CART_SESSION_PREFIX)) {
                $found[] = self::positiveId($value);
            }
        }

        return 1 === \count($found) ? $found[0] : null;
    }

    private function order(Request $request): ?OrderInterface
    {
        $orderId = self::positiveId($request->attributes->get(self::ORDER_ATTRIBUTE));
        if (null === $orderId || null === $this->entityManager || null === $this->orderClass || '' === $this->orderClass) {
            return null;
        }

        $rootClass = $this->entityManager->getClassMetadata($this->orderClass)->rootEntityName;
        $order = $this->entityManager->getUnitOfWork()->tryGetById($orderId, $rootClass);
        if (!$order instanceof OrderInterface || $order->getId() !== $orderId || null === $order->getNumber()) {
            return null;
        }

        return $order;
    }

    private static function positiveId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (\is_string($value) && 1 === preg_match('/^[1-9][0-9]{0,18}$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
