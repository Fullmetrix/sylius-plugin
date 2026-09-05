<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CookieReader
{
    private const COOKIE_PATTERN = '/^[a-zA-Z0-9_\-]{1,64}$/';

    private const MAX_CONTACT_LEN = 8192;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function visitorId(): ?string
    {
        return $this->sanitize($this->request()?->cookies->get('fm_vid'));
    }

    public function sessionId(): ?string
    {
        return $this->sanitize($this->request()?->cookies->get('fm_sid'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contact(): ?array
    {
        $raw = $this->request()?->cookies->get('fm_cid');
        if (null === $raw) {
            return null;
        }
        if (\strlen($raw) > self::MAX_CONTACT_LEN) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    public function pageUrl(): ?string
    {
        return $this->request()?->getUri();
    }

    private function request(): ?Request
    {
        return $this->requestStack->getMainRequest();
    }

    private function sanitize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        if (1 !== preg_match(self::COOKIE_PATTERN, $value)) {
            return null;
        }

        return $value;
    }
}
