<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Api;

use Fullmetrix\SyliusPlugin\Security\HmacRequestVerifier;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\EntityPaginator;
use Fullmetrix\SyliusPlugin\Service\EntitySerializer;
use Fullmetrix\SyliusPlugin\Service\StoreSettingsProvider;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExportController
{
    public function __construct(
        private readonly HmacRequestVerifier $verifier,
        private readonly ConfigStore $config,
        private readonly EntityPaginator $paginator,
        private readonly EntitySerializer $serializer,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly string $pluginVersion,
    ) {
    }

    public function export(Request $request): Response
    {
        if (!$this->verifier->verify($request)) {
            return $this->unauthorized();
        }

        $type = (string) $request->query->get('type', 'orders');
        $page = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('per_page', 100);
        $since = $request->query->get('since');
        $since = \is_string($since) && '' !== $since ? $since : null;

        if ('settings' === $type) {
            return new JsonResponse([
                'success' => true,
                'settings' => $this->storeSettings->get(),
                'meta' => $this->meta(),
            ]);
        }

        $result = $this->paginator->paginate($type, $page, $perPage, $since);
        $items = [];
        foreach ($result['items'] as $entity) {
            $serialized = $this->serializeEntity($type, $entity);
            if (null !== $serialized) {
                $items[] = $serialized;
            }
        }

        $totalPages = $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 0;

        $this->config->set(ConfigStore::KEY_EXPORT_COUNT, ((int) $this->config->get(ConfigStore::KEY_EXPORT_COUNT, 0)) + 1);

        return new JsonResponse([
            'success' => true,
            'data' => $items,
            $type => $items,
            'meta' => array_merge($this->meta(), [
                'total' => $result['total'],
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
                'mode' => 'fast',
            ]),
        ]);
    }

    public function counts(Request $request): Response
    {
        if (!$this->verifier->verify($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse([
            'success' => true,
            'counts' => [
                'orders' => $this->paginator->countByEntity('orders'),
                'customers' => $this->paginator->countByEntity('customers'),
                'products' => $this->paginator->countByEntity('products'),
                'categories' => $this->paginator->countByEntity('categories'),
                'coupons' => $this->paginator->countByEntity('coupons'),
                'refunds' => 0,
            ],
        ]);
    }

    public function updated(Request $request): Response
    {
        if (!$this->verifier->verify($request)) {
            return $this->unauthorized();
        }

        $type = (string) $request->query->get('type', 'orders');
        $days = (int) $request->query->get('days', 30);
        $hours = (int) $request->query->get('hours', 0);
        $limit = (int) $request->query->get('limit', 200_000);
        $offset = (int) $request->query->get('offset', 0);

        $items = $this->paginator->recentlyUpdated($type, $days, $hours, $limit, $offset);

        return new JsonResponse([
            'success' => true,
            'type' => $type,
            'from' => (new \DateTimeImmutable())->modify('-' . $days . ' days -' . $hours . ' hours')->format('Y-m-d H:i:s'),
            'count' => \count($items),
            'items' => $items,
        ]);
    }

    private function serializeEntity(string $type, object $entity): ?array
    {
        return match (true) {
            ('orders' === $type) && $entity instanceof OrderInterface => $this->serializer->serializeOrder($entity),
            ('refunds' === $type) && $entity instanceof OrderInterface => $this->serializer->serializeRefund($entity),
            ('customers' === $type) && $entity instanceof CustomerInterface => $this->serializer->serializeCustomer($entity),
            ('products' === $type) && $entity instanceof ProductInterface => $this->serializer->serializeProduct($entity),
            ('categories' === $type) && $entity instanceof TaxonInterface => $this->serializer->serializeCategory($entity),
            ('coupons' === $type) && $entity instanceof PromotionInterface => $this->serializer->serializeCoupon($entity),
            default => null,
        };
    }

    private function meta(): array
    {
        return [
            'pluginVersion' => $this->pluginVersion,
            'exportedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
