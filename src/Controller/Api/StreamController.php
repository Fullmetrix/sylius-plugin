<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Api;

use Fullmetrix\SyliusPlugin\Security\HmacRequestVerifier;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\EntityPaginator;
use Fullmetrix\SyliusPlugin\Service\EntitySerializer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamController
{
    private const ENTITIES = ['orders', 'customers', 'products', 'categories', 'coupons', 'refunds'];

    public function __construct(
        private readonly HmacRequestVerifier $verifier,
        private readonly ConfigStore $config,
        private readonly EntityPaginator $paginator,
        private readonly EntitySerializer $serializer,
        private readonly string $pluginVersion,
    ) {
    }

    public function streamAll(Request $request): Response
    {
        if (!$this->verifier->verify($request)) {
            return new JsonResponse(['success' => false, 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $since = $this->parseSince($request);
        $response = $this->ndjsonResponse(function () use ($since): \Generator {
            yield $this->encode([
                'type' => 'meta',
                'started_at' => $this->iso(),
                'mode' => 'fast_stream',
                'version' => $this->pluginVersion,
            ]);

            $totalCount = 0;
            foreach (self::ENTITIES as $entity) {
                $count = 0;
                foreach ($this->paginator->streamKeyset($entity, 1000, $since) as $row) {
                    $payload = $this->serializeRow($entity, $row);
                    if (null === $payload) {
                        continue;
                    }
                    yield $this->encode(['type' => $this->lineType($entity), 'data' => $payload]);
                    ++$count;
                }
                yield $this->encode(['type' => 'entity_complete', 'entity' => $entity, 'count' => $count]);
                $totalCount += $count;
            }

            yield $this->encode(['type' => 'done', 'completed_at' => $this->iso(), 'count' => $totalCount]);
        });

        $this->config->set(ConfigStore::KEY_EXPORT_COUNT, ((int) $this->config->get(ConfigStore::KEY_EXPORT_COUNT, 0)) + 1);

        return $response;
    }

    public function streamEntity(Request $request, string $entity): Response
    {
        if (!$this->verifier->verify($request)) {
            return new JsonResponse(['success' => false, 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (null === $this->paginator->resolveClass($entity)) {
            return new JsonResponse(['success' => false, 'error' => 'unknown_entity'], Response::HTTP_BAD_REQUEST);
        }

        $since = $this->parseSince($request);
        $response = $this->ndjsonResponse(function () use ($entity, $since): \Generator {
            yield $this->encode([
                'type' => 'meta',
                'entity' => $entity,
                'started_at' => $this->iso(),
                'mode' => 'fast_stream',
                'version' => $this->pluginVersion,
            ]);

            $count = 0;
            foreach ($this->paginator->streamKeyset($entity, 1000, $since) as $row) {
                $payload = $this->serializeRow($entity, $row);
                if (null === $payload) {
                    continue;
                }
                yield $this->encode(['type' => $this->lineType($entity), 'data' => $payload]);
                ++$count;
            }

            yield $this->encode(['type' => 'entity_complete', 'entity' => $entity, 'count' => $count]);
            yield $this->encode(['type' => 'done', 'completed_at' => $this->iso(), 'count' => $count]);
        });

        $this->config->set(ConfigStore::KEY_EXPORT_COUNT, ((int) $this->config->get(ConfigStore::KEY_EXPORT_COUNT, 0)) + 1);

        return $response;
    }

    private function ndjsonResponse(callable $generator): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($generator) {
            foreach ($generator() as $line) {
                echo $line;
                flush();
            }
        });
        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }

    private function serializeRow(string $entity, object $row): ?array
    {
        return match (true) {
            ('orders' === $entity) && $row instanceof OrderInterface => $this->serializer->serializeOrder($row),
            ('refunds' === $entity) && $row instanceof OrderInterface => $this->serializer->serializeRefund($row),
            ('customers' === $entity) && $row instanceof CustomerInterface => $this->serializer->serializeCustomer($row),
            ('products' === $entity) && $row instanceof ProductInterface => $this->serializer->serializeProduct($row),
            ('categories' === $entity) && $row instanceof TaxonInterface => $this->serializer->serializeCategory($row),
            ('coupons' === $entity) && $row instanceof PromotionInterface => $this->serializer->serializeCoupon($row),
            default => null,
        };
    }

    private function lineType(string $entity): string
    {
        return match ($entity) {
            'orders' => 'order',
            'refunds' => 'refund',
            'customers' => 'customer',
            'products' => 'product',
            'categories' => 'category',
            'coupons' => 'coupon',
            default => $entity,
        };
    }

    private function parseSince(Request $request): ?string
    {
        $since = $request->query->get('since');
        if (!\is_string($since) || '' === $since) {
            return null;
        }

        $syncType = (string) $request->query->get('sync_type', 'full');
        if ('incremental' !== $syncType) {
            return null;
        }

        return $since;
    }

    private function encode(array $row): string
    {
        return (json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}') . "\n";
    }

    private function iso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
