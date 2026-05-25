<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class CheckoutConsentSender
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly string $consentEndpoint,
    ) {
    }

    /**
     * @param array<int, string> $channels
     */
    public function send(?string $email, ?string $phone, bool $consent, array $channels, ?string $pageUrl): void
    {
        if (!$this->config->isActive()) {
            return;
        }
        $code = $this->config->getConnectionCode();
        if (null === $code) {
            return;
        }
        if (null === $email && null === $phone) {
            return;
        }

        $payload = [
            'key' => $code,
            'consent' => $consent,
            'channels' => array_values($channels),
            'pageUrl' => $pageUrl,
            'email' => $email,
            'phone' => $phone,
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $response = $this->http->post($this->consentEndpoint, $body, [], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Checkout consent forward failed', [
                'status' => $response['status'],
                'error' => $response['error'],
            ]);
        }
    }
}
