<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class PluginConfigProvider
{
    private const TTL_SECONDS = 1800;

    private const RETRY_AFTER_FAILURE_SECONDS = 300;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly HmacSigner $signer,
        private readonly Logger $logger,
        private readonly DeliveryBreaker $breaker,
        private readonly string $apiBase,
    ) {
    }

    public function getConfig(): ?array
    {
        $cached = $this->config->get(ConfigStore::KEY_PLUGIN_CONFIG);
        if (\is_array($cached) && isset($cached['data']) && \is_array($cached['data'])) {
            return $cached['data'];
        }

        return null;
    }

    public function getCheckoutConsent(): ?array
    {
        $config = $this->getConfig();
        if (!\is_array($config) || empty($config['checkoutConsent'])) {
            return null;
        }

        $consent = $config['checkoutConsent'];
        if (!\is_array($consent) || empty($consent['label'])) {
            return null;
        }

        return $consent;
    }

    public function refreshIfStale(): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $cached = $this->config->get(ConfigStore::KEY_PLUGIN_CONFIG);
        $cached = \is_array($cached) ? $cached : [];
        $now = time();

        if (isset($cached['fetchedAt']) && ($now - (int) $cached['fetchedAt']) < self::TTL_SECONDS) {
            return;
        }
        if (isset($cached['retryAt']) && (int) $cached['retryAt'] > $now) {
            return;
        }
        if (!$this->breaker->allows()) {
            return;
        }

        $cached['retryAt'] = $now + self::RETRY_AFTER_FAILURE_SECONDS;
        if (!$this->config->set(ConfigStore::KEY_PLUGIN_CONFIG, $cached)) {
            return;
        }

        $fresh = $this->fetch();
        if (null !== $fresh) {
            $this->config->set(ConfigStore::KEY_PLUGIN_CONFIG, ['fetchedAt' => $now, 'data' => $fresh]);
        }
    }

    private function fetch(): ?array
    {
        $code = $this->config->getConnectionCode();
        $secret = $this->config->getConnectionSecret();
        if (null === $code || null === $secret) {
            return null;
        }

        $headers = $this->signer->buildHeaders($secret, $code, '');
        $response = $this->http->get($this->apiBase . '/config', $headers, true, $this->breaker->remainingMs());
        $this->breaker->record($response);

        if (200 !== $response['status']) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Plugin config fetch failed', [
                'status' => $response['status'],
                'error' => $response['error'],
            ]);

            return null;
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : null;
    }
}
