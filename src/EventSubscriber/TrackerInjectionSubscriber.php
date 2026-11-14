<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\LocalTracker;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
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
        if (LocalTracker::KEEPER_PATH === $path || str_starts_with($path, '/admin') || str_starts_with($path, '/fullmetrix/api') || str_starts_with($path, '/_')) {
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
        if (LocalTracker::trackerDisabled($pluginConfig)) {
            return;
        }

        $tag = LocalTracker::tag($this->config->get(ConfigStore::KEY_TAG));
        $key = '' !== $tag['tagKey'] ? $tag['tagKey'] : $code;
        $attributes = ['data-kvo' => $key, 'data-kvo-p' => LocalTracker::PLATFORM, 'data-kvo-v' => $this->pluginVersion];
        if (LocalTracker::keeper($this->config->get(ConfigStore::KEY_KEEPER))['enabled']) {
            $attributes['data-kvo-k'] = $request->getBaseUrl() . LocalTracker::KEEPER_PATH;
        }
        $origin = LocalTracker::acceptedSendOrigin($tag['origin'], LocalTracker::declaredDomain($request->getHost()));
        if (LocalTracker::DEFAULT_ORIGIN !== $origin) {
            $attributes['data-kvo-o'] = $origin;
        }
        $privatePage = !$response->headers->hasCacheControlDirective('public');
        $cartId = $privatePage ? $this->cartId($request) : null;
        if (null !== $cartId) {
            $attributes['data-kvo-c'] = (string) $cartId;
        }
        $attributes += LocalTracker::EXCLUSION_ATTRIBUTES;
        $fallback = LocalTracker::fallbackUrl($key);
        $local = LocalTracker::usable($this->config->get(ConfigStore::KEY_LOCAL_TRACKER), time());

        if (null === $local) {
            $markup = sprintf('<script async src="%s"%s></script>', htmlspecialchars($fallback, \ENT_QUOTES, 'UTF-8'), self::attributes($attributes));
        } else {
            $markup = sprintf(
                '<script async src="%s"%s onerror="%s"></script>',
                htmlspecialchars($request->getBasePath() . $local['path'], \ENT_QUOTES, 'UTF-8'),
                self::attributes($attributes),
                htmlspecialchars(self::fallbackScript($fallback), \ENT_QUOTES, 'UTF-8'),
            );
        }

        $injected = preg_replace('/<\/head>/i', $markup . '</head>', $content, 1);
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
            . ';s.async=true;for(i=0;i<o.attributes.length;i++){a=o.attributes[i];if(a.name!==\'src\'&&a.name!==\'onerror\'&&a.name!==\'async\')s.setAttribute(a.name,a.value)}document.head.appendChild(s)';
    }

    private function orderMarker(Request $request): string
    {
        try {
            $order = $this->order($request);
            if (null === $order) {
                return '';
            }
            $json = json_encode([
                'id' => (string) $order->getId(),
                'number' => (string) $order->getNumber(),
                'total' => number_format($order->getTotal() / 100, 2, '.', ''),
                'currency' => (string) $order->getCurrencyCode(),
            ], \JSON_UNESCAPED_SLASHES);

            return '<div id="kvo-o" hidden' . self::attributes(['data-kvo-order' => (string) $json]) . '></div>';
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
        try {
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
        } catch (\Throwable) {
            return null;
        }
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
