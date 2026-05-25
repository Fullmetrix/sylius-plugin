<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class TrackingQueue
{
    public const EVENT_IDENTIFY = 'identify';
    public const EVENT_CART_UPDATED = 'cart_updated';
    public const EVENT_ADDED_TO_CART = 'added_to_cart';
    public const EVENT_REMOVED_FROM_CART = 'removed_from_cart';

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var array<string, int> */
    private array $singletonIndex = [];

    public function __construct(
        private readonly ConfigStore $config,
        private readonly CookieReader $cookies,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly Logger $logger,
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

        $secret = $this->config->getConnectionSecret();
        $code = $this->config->getConnectionCode();
        if (null === $secret || null === $code) {
            $this->events = [];
            $this->singletonIndex = [];

            return;
        }

        $payload = [
            'events' => array_values($this->events),
            'visitor_id' => $this->cookies->visitorId(),
            'session_id' => $this->cookies->sessionId(),
            'plugin_version' => 'server-' . $this->pluginVersion,
            'timestamp' => $this->signer->nowMs(),
        ];

        $this->events = [];
        $this->singletonIndex = [];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = $this->signer->buildHeaders($secret, $code, $body);

        $response = $this->http->post($this->eventsEndpoint, $body, $headers, true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Tracking flush failed', [
                'status' => $response['status'],
                'error' => $response['error'],
            ]);
        }
    }
}
