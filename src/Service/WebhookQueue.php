<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;

final class WebhookQueue
{
    public const TYPE_ORDER = 'order';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_CATEGORY = 'category';

    public const TYPE_COUPON = 'coupon';

    public const TYPE_REFUND = 'refund';

    /** @var array<string, array{type: string, id: int|string}> */
    private array $queue = [];

    public function __construct(
        private readonly ConfigStore $config,
        private readonly EntityManagerInterface $em,
        private readonly EntitySerializer $serializer,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly string $webhookEndpoint,
        private readonly string $pluginVersion,
    ) {
    }

    public function enqueue(string $type, int|string $id): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $key = $type . ':' . $id;
        $this->queue[$key] = ['type' => $type, 'id' => $id];
    }

    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $secret = $this->config->getConnectionSecret();
        $code = $this->config->getConnectionCode();
        if (null === $secret || null === $code) {
            $this->queue = [];

            return;
        }

        $items = $this->queue;
        $this->queue = [];

        foreach ($items as $item) {
            $data = $this->resolve($item['type'], $item['id']);
            if (null === $data) {
                continue;
            }

            $payload = [
                'event' => $item['type'] . '.updated',
                'entity_type' => $item['type'],
                'data' => $data,
                'plugin_version' => $this->pluginVersion,
                'timestamp' => $this->signer->nowMs(),
            ];

            $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
            $headers = $this->signer->buildHeaders($secret, $code, $body);

            $response = $this->http->post($this->webhookEndpoint, $body, $headers, true);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $this->logger->log(Logger::TYPE_WEBHOOK, 'Webhook send failed', [
                    'type' => $item['type'],
                    'id' => $item['id'],
                    'status' => $response['status'],
                    'error' => $response['error'],
                ]);
            }
        }
    }

    private function resolve(string $type, int|string $id): ?array
    {
        return match ($type) {
            self::TYPE_ORDER => $this->resolveEntity(OrderInterface::class, $id, fn ($e) => $this->serializer->serializeOrder($e)),
            self::TYPE_REFUND => $this->resolveEntity(OrderInterface::class, $id, fn ($e) => $this->serializer->serializeRefund($e)),
            self::TYPE_CUSTOMER => $this->resolveEntity(CustomerInterface::class, $id, fn ($e) => $this->serializer->serializeCustomer($e)),
            self::TYPE_PRODUCT => $this->resolveEntity(ProductInterface::class, $id, fn ($e) => $this->serializer->serializeProduct($e)),
            self::TYPE_CATEGORY => $this->resolveEntity(TaxonInterface::class, $id, fn ($e) => $this->serializer->serializeCategory($e)),
            self::TYPE_COUPON => $this->resolveEntity(PromotionInterface::class, $id, fn ($e) => $this->serializer->serializeCoupon($e)),
            default => null,
        };
    }

    private function resolveEntity(string $class, int|string $id, callable $serialize): ?array
    {
        try {
            $entity = $this->em->getRepository($class)->find($id);
            if (null === $entity) {
                return null;
            }

            return $serialize($entity);
        } catch (\Throwable) {
            return null;
        }
    }
}
