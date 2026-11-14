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
    public const COOKIE_NAME = '_kvo_v';

    public const LEGACY_COOKIE_NAME = 'fm_vid';

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
        $path = $request->getPathInfo();
        if ((LocalTracker::KEEPER_PATH !== $path && LocalTracker::LEGACY_KEEPER_PATH !== $path) || 'POST' !== $request->getMethod()) {
            return;
        }

        try {
            $cookies = $this->cookiesFor($request);
            $response = \is_int($cookies) ? self::response($cookies) : self::response(Response::HTTP_NO_CONTENT, $cookies);
        } catch (\Throwable) {
            $cookies = null;
            $response = self::response(Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, \is_array($cookies) ? $cookies : false);
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
        if (\is_array($own) && Response::HTTP_NO_CONTENT === $event->getResponse()->getStatusCode()) {
            foreach ($own as $cookie) {
                $headers->setCookie($cookie);
            }
        }
    }

    /** @return array<int, Cookie>|int */
    private function cookiesFor(Request $request): array|int
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

        $header = (string) $request->headers->get('Cookie', '');
        $fresh = self::cookieValues($header, self::COOKIE_NAME);
        $legacy = self::cookieValues($header, self::LEGACY_COOKIE_NAME);
        if (\count($fresh) > 1 || \count($legacy) > 1) {
            return Response::HTTP_BAD_REQUEST;
        }
        if (isset($fresh[0]) && '' === $fresh[0]) {
            $fresh = [];
        }
        if ([] === $fresh && [] === $legacy) {
            return Response::HTTP_BAD_REQUEST;
        }
        $value = $fresh[0] ?? $legacy[0];
        if (1 !== preg_match(self::UUID_V4, $value) || $request->getContent() !== $value) {
            return Response::HTTP_BAD_REQUEST;
        }

        $cookies = [self::cookie(self::COOKIE_NAME, $value, $keeper['maxAgeDays'], $request->isSecure())];
        if (isset($legacy[0]) && $legacy[0] === $value) {
            $cookies[] = self::cookie(self::LEGACY_COOKIE_NAME, $value, $keeper['maxAgeDays'], $request->isSecure());
        }

        return $cookies;
    }

    private static function cookie(string $name, string $value, int $days, bool $secure): Cookie
    {
        return Cookie::create($name, $value, time() + $days * 86400, '/', null, $secure, false, true, Cookie::SAMESITE_LAX);
    }

    /** @return array<int, string> */
    private static function cookieValues(string $header, string $name): array
    {
        $found = [];
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if ($name === trim($parts[0])) {
                $found[] = trim($parts[1] ?? '');
            }
        }

        return $found;
    }

    /** @param array<int, Cookie>|null $cookies */
    private static function response(int $status, ?array $cookies = null): Response
    {
        $response = new Response('', $status, [
            'Cache-Control' => 'no-store, private',
            'CDN-Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
        if (null !== $cookies) {
            $response->headers->set('X-Fm-Keeper', '1');
            $response->headers->set('X-Kvo-K', '1');
            foreach ($cookies as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        return $response;
    }
}
