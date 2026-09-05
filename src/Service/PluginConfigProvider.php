<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class PluginConfigProvider
{
    private const TTL_SECONDS = 1800;

    private bool $loaded = false;

    private ?array $data = null;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly HmacSigner $signer,
        private readonly Logger $logger,
        private readonly string $apiBase,
    ) {
    }

    public function getConfig(): ?array
    {
        if ($this->loaded) {
            return $this->data;
        }

        $cached = $this->config->get(ConfigStore::KEY_PLUGIN_CONFIG);
        $now = time();

        if (\is_array($cached) && isset($cached['fetchedAt'], $cached['data']) &&
            ($now - (int) $cached['fetchedAt']) < self::TTL_SECONDS) {
            $this->loaded = true;
            $this->data = \is_array($cached['data']) ? $cached['data'] : null;

            return $this->data;
        }

        $fresh = $this->fetch();
        if (null !== $fresh) {
            $this->config->set(ConfigStore::KEY_PLUGIN_CONFIG, ['fetchedAt' => $now, 'data' => $fresh]);
            $this->loaded = true;
            $this->data = $fresh;

            return $fresh;
        }

        if (\is_array($cached) && isset($cached['data']) && \is_array($cached['data'])) {
            $this->loaded = true;
            $this->data = $cached['data'];

            return $this->data;
        }

        $this->loaded = true;
        $this->data = null;

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

    private function fetch(): ?array
    {
        if (!$this->config->isRegistered()) {
            return null;
        }

        $code = $this->config->getConnectionCode();
        $secret = $this->config->getConnectionSecret();
        if (null === $code || null === $secret) {
            return null;
        }

        $headers = $this->signer->buildHeaders($secret, $code, '');
        $response = $this->http->get($this->apiBase . '/config', $headers);

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
