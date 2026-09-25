<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;
use Symfony\Contracts\Service\ResetInterface;

final class WebhookQueue implements ResetInterface
{
    public const TYPE_ORDER = 'order';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_CATEGORY = 'category';

    public const TYPE_COUPON = 'coupon';

    public const TYPE_REFUND = 'refund';

    public const MAX_PER_REQUEST = 50;

    /** @var array<string, array{type: string, id: int|string}> */
    private array $queue = [];

    private int $overflow = 0;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly EntityManagerInterface $em,
        private readonly EntitySerializer $serializer,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly DeliveryBreaker $breaker,
        private readonly string $webhookEndpoint,
        private readonly string $pluginVersion,
    ) {
    }

    public function enqueue(string $type, int|string $id): void
    {
        $key = $type . ':' . $id;
        if (\count($this->queue) >= self::MAX_PER_REQUEST && !isset($this->queue[$key])) {
            ++$this->overflow;

            return;
        }

        $this->queue[$key] = ['type' => $type, 'id' => $id];
    }

    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $items = $this->queue;
        $overflow = $this->overflow;
        $this->reset();

        if (!$this->config->isActive()) {
            return;
        }
        if ($overflow > 0) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Webhooks dropped', ['reason' => 'queue_full']);
        }

        $secret = $this->config->getConnectionSecret();
        $code = $this->config->getConnectionCode();
        if (null === $secret || null === $code) {
            return;
        }

        foreach ($items as $item) {
            if ($this->blocked()) {
                return;
            }
            foreach ($this->resolve($item['type'], $item['id']) as $data) {
                if ($this->blocked()) {
                    return;
                }
                $this->send($item, $data, $secret, $code);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->overflow = 0;
    }

    private function blocked(): bool
    {
        $reason = $this->breaker->blockReason();
        if (null === $reason) {
            return false;
        }

        $this->logger->log(Logger::TYPE_WEBHOOK, 'Webhooks dropped', ['reason' => $reason]);

        return true;
    }

    /** @param array{type: string, id: int|string} $item */
    private function send(array $item, array $data, string $secret, string $code): void
    {
        $payload = [
            'event' => $item['type'] . '.updated',
            'entity_type' => $item['type'],
            'data' => $data,
            'plugin_version' => $this->pluginVersion,
            'timestamp' => $this->signer->nowMs(),
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = $this->signer->buildHeaders($secret, $code, $body);

        $response = $this->http->post($this->webhookEndpoint, $body, $headers, true, maxTotalMs: $this->breaker->remainingMs());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->log(Logger::TYPE_WEBHOOK, 'Webhook send failed', [
                'type' => $item['type'],
                'id' => $item['id'],
                'status' => $response['status'],
                'error' => $response['error'],
            ]);
        }

        $this->breaker->record($response);
    }

    /** @return array<int, array<string, mixed>> */
    private function resolve(string $type, int|string $id): array
    {
        return match ($type) {
            self::TYPE_ORDER => $this->resolveEntity(OrderInterface::class, $id, fn ($e) => [$this->serializer->serializeOrder($e)]),
            self::TYPE_REFUND => $this->resolveEntity(OrderInterface::class, $id, fn ($e) => [$this->serializer->serializeRefund($e)]),
            self::TYPE_CUSTOMER => $this->resolveEntity(CustomerInterface::class, $id, fn ($e) => [$this->serializer->serializeCustomer($e)]),
            self::TYPE_PRODUCT => $this->resolveEntity(ProductInterface::class, $id, fn ($e) => $this->serializer->serializeProductRows($e)),
            self::TYPE_CATEGORY => $this->resolveEntity(TaxonInterface::class, $id, fn ($e) => [$this->serializer->serializeCategory($e)]),
            self::TYPE_COUPON => $this->resolveEntity(PromotionInterface::class, $id, fn ($e) => [$this->serializer->serializeCoupon($e)]),
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveEntity(string $class, int|string $id, callable $serialize): array
    {
        try {
            $entity = $this->em->getRepository($class)->find($id);
            if (null === $entity) {
                return [];
            }

            return $serialize($entity);
        } catch (\Throwable) {
            return [];
        }
    }
}
