<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class CartLinkResolver
{
    private const DOWN_KEY = 'cart_resolve_down_at';
    private const FAILURE_KEY = 'cart_resolve_failures';
    private const DOWN_TTL = 60;
    private const FAILURES_BEFORE_DOWN = 3;
    private const CONNECT_TIMEOUT_MS = 300;
    private const TOTAL_TIMEOUT_MS = 1500;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly string $apiBase,
    ) {
    }

    private function recordFailure(): void
    {
        $failures = ((int) $this->config->get(self::FAILURE_KEY, '0')) + 1;
        $this->config->set(self::FAILURE_KEY, (string) $failures);

        if ($failures >= self::FAILURES_BEFORE_DOWN) {
            $this->config->set(self::DOWN_KEY, (string) time());
            $this->config->set(self::FAILURE_KEY, '0');
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, c: array<int, mixed>, target: string}|null
     */
    public function resolve(string $linkId): ?array
    {
        if ('' === $linkId || \strlen($linkId) > 64) {
            return null;
        }

        $secret = $this->config->getConnectionSecret();
        $code = $this->config->getConnectionCode();
        if (null === $secret || null === $code) {
            return null;
        }

        $downAt = (int) $this->config->get(self::DOWN_KEY, '0');
        if ($downAt > 0 && (time() - $downAt) < self::DOWN_TTL) {
            return null;
        }

        $body = json_encode(['id' => $linkId]);
        if (!\is_string($body)) {
            return null;
        }

        $headers = $this->signer->buildHeaders($secret, $code, $body);

        $response = $this->http->post(
            rtrim($this->apiBase, '/') . '/cart/resolve',
            $body,
            $headers,
            false,
            self::CONNECT_TIMEOUT_MS,
            self::TOTAL_TIMEOUT_MS,
        );

        $status = (int) $response['status'];
        if (0 === $status || $status >= 500) {
            $this->recordFailure();

            return null;
        }

        $this->config->set(self::FAILURE_KEY, '0');

        if (200 !== $status) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            return null;
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $items[] = [
                'id' => $item['id'] ?? null,
                'v' => $item['variation'] ?? null,
                'q' => $item['quantity'] ?? 1,
            ];
        }

        return [
            'items' => $items,
            'c' => \is_array($decoded['coupons'] ?? null) ? $decoded['coupons'] : [],
            'target' => \is_string($decoded['target'] ?? null) ? $decoded['target'] : 'cart',
        ];
    }
}
