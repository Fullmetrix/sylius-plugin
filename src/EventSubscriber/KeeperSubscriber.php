<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\LocalTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class KeeperSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'fm_vid';

    private const REQUEST_ATTRIBUTE = '_fullmetrix_keeper';

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private readonly ConfigStore $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 512],
            KernelEvents::RESPONSE => ['onResponse', -9000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (LocalTracker::KEEPER_PATH !== $request->getPathInfo() || 'POST' !== $request->getMethod()) {
            return;
        }

        try {
            $cookie = $this->cookieFor($request);
            $response = \is_int($cookie) ? self::response($cookie) : self::response(Response::HTTP_NO_CONTENT, $cookie);
        } catch (\Throwable) {
            $cookie = null;
            $response = self::response(Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $cookie instanceof Cookie ? $cookie : false);
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach ($headers->getCookies() as $cookie) {
            $headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }
        $own = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($own instanceof Cookie && Response::HTTP_NO_CONTENT === $event->getResponse()->getStatusCode()) {
            $headers->setCookie($own);
        }
    }

    private function cookieFor(Request $request): Cookie|int
    {
        if (!$this->config->isActive() || LocalTracker::trackerDisabled($this->config->get(ConfigStore::KEY_PLUGIN_CONFIG))) {
            return Response::HTTP_NOT_FOUND;
        }
        $keeper = LocalTracker::keeper($this->config->get(ConfigStore::KEY_KEEPER));
        if (!$keeper['enabled']) {
            return Response::HTTP_NOT_FOUND;
        }

        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && 'same-origin' !== $site) {
            return Response::HTTP_FORBIDDEN;
        }

        $value = self::cookieValue((string) $request->headers->get('Cookie', ''));
        if (null === $value || 1 !== preg_match(self::UUID_V4, $value) || $request->getContent() !== $value) {
            return Response::HTTP_BAD_REQUEST;
        }

        return Cookie::create(
            self::COOKIE_NAME,
            $value,
            time() + $keeper['maxAgeDays'] * 86400,
            '/',
            null,
            $request->isSecure(),
            false,
            true,
            Cookie::SAMESITE_LAX,
        );
    }

    private static function cookieValue(string $header): ?string
    {
        $found = [];
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (self::COOKIE_NAME === trim($parts[0])) {
                $found[] = trim($parts[1] ?? '');
            }
        }

        return 1 === \count($found) ? $found[0] : null;
    }

    private static function response(int $status, ?Cookie $cookie = null): Response
    {
        $response = new Response('', $status, [
            'Cache-Control' => 'no-store, private',
            'CDN-Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
        if (null !== $cookie) {
            $response->headers->set('X-Fm-Keeper', '1');
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
