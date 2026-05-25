<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class ApiClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly HmacSigner $signer,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly string $apiBase,
        private readonly string $pluginVersion,
    ) {
    }

    /**
     * @return array{success: bool, secret?: string, error?: string, status: int}
     */
    public function register(string $connectionCode, string $siteUrl): array
    {
        $payload = [
            'connectionCode' => $connectionCode,
            'siteUrl' => $siteUrl,
            'pluginVersion' => $this->pluginVersion,
            'platform' => 'sylius',
            'storeSettings' => $this->storeSettings->get(),
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $response = $this->http->post(
            $this->apiBase . '/register',
            $body,
            [HmacSigner::HEADER_PLUGIN_VERSION => $this->pluginVersion],
        );

        if (200 !== $response['status']) {
            return [
                'success' => false,
                'status' => $response['status'],
                'error' => $response['error'] ?? $this->extractError($response['body']),
            ];
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded) || empty($decoded['connectionSecret']) || !\is_string($decoded['connectionSecret'])) {
            return [
                'success' => false,
                'status' => $response['status'],
                'error' => 'invalid_response',
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'secret' => $decoded['connectionSecret'],
        ];
    }

    private function extractError(string $body): string
    {
        $decoded = json_decode($body, true);
        if (\is_array($decoded) && isset($decoded['error']) && \is_string($decoded['error'])) {
            return $decoded['error'];
        }

        return 'request_failed';
    }
}
