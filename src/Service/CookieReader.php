<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CookieReader
{
    private const COOKIE_PATTERN = '/^[a-zA-Z0-9_\-]{1,64}$/';

    private const MAX_CONTACT_LEN = 8192;

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function visitorId(): ?string
    {
        return $this->uuid($this->request()?->cookies->get('_kvo_v')) ?? $this->sanitize($this->request()?->cookies->get('fm_vid'));
    }

    public function sessionId(): ?string
    {
        return $this->uuid($this->request()?->cookies->get('_kvo_s')) ?? $this->sanitize($this->request()?->cookies->get('fm_sid'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contact(): ?array
    {
        $raw = $this->request()?->cookies->get('_kvo_c') ?? $this->request()?->cookies->get('fm_cid');
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

    private function uuid(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match(self::UUID_V4, $value) ? $value : null;
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
