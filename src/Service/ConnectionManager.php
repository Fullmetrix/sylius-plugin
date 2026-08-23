<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class ConnectionManager
{
    public const CODE_PATTERN = '/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly ApiClient $api,
        private readonly Logger $logger,
    ) {
    }

    public static function isValidCode(string $code): bool
    {
        return 1 === preg_match(self::CODE_PATTERN, $code);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function connect(string $code, string $siteUrl): array
    {
        $code = strtoupper(trim($code));
        if (!self::isValidCode($code)) {
            return ['success' => false, 'error' => 'invalid_code_format'];
        }

        $storeCanonicalId = $this->config->get(ConfigStore::KEY_STORE_CANONICAL_ID);
        if (!\is_string($storeCanonicalId) || '' === $storeCanonicalId) {
            $storeCanonicalId = bin2hex(random_bytes(16));
            $this->config->set(ConfigStore::KEY_STORE_CANONICAL_ID, $storeCanonicalId);
        }

        $response = $this->api->register($code, $siteUrl, $storeCanonicalId);
        if (!$response['success']) {
            $this->logger->log(Logger::TYPE_SYNC_ERROR, 'Registration failed', [
                'status' => $response['status'],
                'error' => $response['error'] ?? null,
            ]);

            return ['success' => false, 'error' => $response['error'] ?? 'register_failed'];
        }

        $this->config->set(ConfigStore::KEY_CONNECTION_CODE, $code);
        $this->config->set(ConfigStore::KEY_CONNECTION_SECRET, $response['secret']);
        $this->config->set(ConfigStore::KEY_REGISTERED, true);
        $this->config->set(ConfigStore::KEY_WEBHOOKS_ENABLED, true);

        $this->logger->log(Logger::TYPE_REGISTERED, 'Connected to Fullmetrix', ['code' => $code]);

        return ['success' => true];
    }

    public function disconnect(): void
    {
        $code = $this->config->getConnectionCode();
        $this->config->set(ConfigStore::KEY_REGISTERED, false);
        $this->config->set(ConfigStore::KEY_WEBHOOKS_ENABLED, false);
        $this->config->delete(ConfigStore::KEY_CONNECTION_SECRET);
        $this->logger->log(Logger::TYPE_DISCONNECTED, 'Disconnected from Fullmetrix', ['code' => $code]);
    }
}
