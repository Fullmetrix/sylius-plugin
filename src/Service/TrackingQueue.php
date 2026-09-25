<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Symfony\Contracts\Service\ResetInterface;

final class TrackingQueue implements ResetInterface
{
    public const EVENT_IDENTIFY = 'identify';

    public const EVENT_CART_UPDATED = 'cart_updated';

    public const EVENT_ADDED_TO_CART = 'added_to_cart';

    public const EVENT_REMOVED_FROM_CART = 'removed_from_cart';

    private const MAX_PER_REQUEST = 50;

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var array<string, int> */
    private array $singletonIndex = [];

    private int $overflow = 0;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly CookieReader $cookies,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly DeliveryBreaker $breaker,
        private readonly string $eventsEndpoint,
        private readonly string $pluginVersion,
    ) {
    }

    public function enqueue(string $eventType, array $properties = [], ?array $contact = null, bool $deduplicate = false): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $event = [
            'event_id' => 'srv_' . bin2hex(random_bytes(12)),
            'event_type' => $eventType,
            'properties' => $properties,
            'occurred_at' => $this->signer->nowMs(),
            'contact' => $contact ?? $this->cookies->contact(),
            'page' => ['url' => $this->cookies->pageUrl()],
        ];

        if ($deduplicate && isset($this->singletonIndex[$eventType])) {
            $this->events[$this->singletonIndex[$eventType]] = $event;

            return;
        }

        if (\count($this->events) >= self::MAX_PER_REQUEST) {
            ++$this->overflow;

            return;
        }

        $this->events[] = $event;
        if ($deduplicate) {
            $this->singletonIndex[$eventType] = array_key_last($this->events);
        }
    }

    public function flush(): void
    {
        if (empty($this->events)) {
            return;
        }

        $events = $this->events;
        $overflow = $this->overflow;
        $this->reset();

        $secret = $this->config->getConnectionSecret();
        $code = $this->config->getConnectionCode();
        if (null === $secret || null === $code) {
            return;
        }
        if ($overflow > 0) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Tracking events dropped', ['reason' => 'queue_full']);
        }
        $reason = $this->breaker->blockReason();
        if (null !== $reason) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Tracking events dropped', ['reason' => $reason]);

            return;
        }

        $payload = [
            'events' => array_values($events),
            'visitor_id' => $this->cookies->visitorId(),
            'session_id' => $this->cookies->sessionId(),
            'plugin_version' => 'server-' . $this->pluginVersion,
            'timestamp' => $this->signer->nowMs(),
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = $this->signer->buildHeaders($secret, $code, $body);

        $response = $this->http->post($this->eventsEndpoint, $body, $headers, true, maxTotalMs: $this->breaker->remainingMs());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Tracking flush failed', [
                'status' => $response['status'],
                'error' => $response['error'],
            ]);
        }
        $this->breaker->record($response);
    }

    public function reset(): void
    {
        $this->events = [];
        $this->singletonIndex = [];
        $this->overflow = 0;
    }
}
