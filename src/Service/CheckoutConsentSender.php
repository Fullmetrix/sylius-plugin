<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Symfony\Contracts\Service\ResetInterface;

final class CheckoutConsentSender implements ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $pending = null;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly DeliveryBreaker $breaker,
        private readonly string $consentEndpoint,
    ) {
    }

    /**
     * @param array<int, string> $channels
     */
    public function enqueue(?string $email, ?string $phone, bool $consent, array $channels, ?string $pageUrl): void
    {
        if (null === $email && null === $phone) {
            return;
        }

        $this->pending = [
            'consent' => $consent,
            'channels' => array_values($channels),
            'pageUrl' => $pageUrl,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = null;
        if (null === $pending || !$this->config->isActive()) {
            return;
        }

        $code = $this->config->getConnectionCode();
        if (null === $code) {
            return;
        }
        $reason = $this->breaker->blockReason(true);
        if (null !== $reason) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Checkout consent dropped', ['reason' => $reason]);

            return;
        }

        $payload = ['key' => $code] + $pending;

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $response = $this->http->post($this->consentEndpoint, $body, [], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Checkout consent forward failed', [
                'status' => $response['status'],
                'error' => $response['error'],
            ]);
        }
        $this->breaker->record($response);
    }

    public function reset(): void
    {
        $this->pending = null;
    }
}
