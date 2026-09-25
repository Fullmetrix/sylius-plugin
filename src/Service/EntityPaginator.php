<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;

final class EntityPaginator
{
    private const MAX_PER_PAGE = 500;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{items: array<int, object>, total: int}
     */
    public function paginate(string $entity, int $page, int $perPage, ?string $since = null): array
    {
        $class = $this->resolveClass($entity);
        if (null === $class) {
            return ['items' => [], 'total' => 0];
        }

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $qb = $this->em->getRepository($class)->createQueryBuilder('e');
        $this->applyEntityFilters($qb, $entity, $class, $since);
        $qb->orderBy('e.id', 'ASC');
        $qb->setFirstResult(($page - 1) * $perPage);
        $qb->setMaxResults($perPage);

        $paginator = new Paginator($qb->getQuery(), false);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => $paginator->count(),
        ];
    }

    /**
     * @return iterable<object>
     */
    public function streamKeyset(string $entity, int $batchSize = 1000, ?string $since = null, int $fromId = 0): iterable
    {
        $class = $this->resolveClass($entity);
        if (null === $class) {
            return;
        }

        $lastId = $fromId;
        while (true) {
            $qb = $this->em->getRepository($class)->createQueryBuilder('e')
                ->andWhere('e.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($batchSize);

            $this->applyEntityFilters($qb, $entity, $class, $since);

            $rows = $qb->getQuery()->getResult();
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                yield $row;
                if (method_exists($row, 'getId')) {
                    $lastId = (int) $row->getId();
                }
            }

            $this->em->clear();

            if (\count($rows) < $batchSize) {
                break;
            }
        }
    }

    public function countByEntity(string $entity, ?string $since = null): int
    {
        $class = $this->resolveClass($entity);
        if (null === $class) {
            return 0;
        }

        $qb = $this->em->getRepository($class)->createQueryBuilder('e')->select('COUNT(e.id)');
        $this->applyEntityFilters($qb, $entity, $class, $since);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array{id: int, last_updated: int}>
     */
    public function recentlyUpdated(string $entity, int $days, int $hours, int $limit, int $offset): array
    {
        $class = $this->resolveClass($entity);
        if (null === $class || !$this->hasField($class, 'updatedAt')) {
            return [];
        }

        $cutoff = (new \DateTimeImmutable())->modify('-' . $days . ' days -' . $hours . ' hours');
        $qb = $this->em->getRepository($class)->createQueryBuilder('e')
            ->select('e.id', 'e.updatedAt')
            ->where('e.updatedAt >= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('e.updatedAt', 'DESC')
            ->setMaxResults(min(500_000, $limit))
            ->setFirstResult($offset);

        $this->applyEntityFilters($qb, $entity, $class, null);

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'last_updated' => $row['updatedAt'] instanceof \DateTimeInterface ? $row['updatedAt']->getTimestamp() : 0,
            ];
        }

        return $result;
    }

    private function applyEntityFilters(QueryBuilder $qb, string $entity, string $class, ?string $since): void
    {
        if ('orders' === $entity || 'refunds' === $entity) {
            $qb->andWhere('e.state != :cartState')->setParameter('cartState', OrderInterface::STATE_CART);
        }
        if ('coupons' === $entity) {
            $qb->andWhere('e.couponBased = :cb')->setParameter('cb', true);
        }
        if ('refunds' === $entity) {
            $qb->andWhere('e.paymentState = :ps')->setParameter('ps', 'refunded');
        }
        if (null !== $since && $this->hasField($class, 'updatedAt')) {
            $qb->andWhere($this->hasField($class, 'createdAt')
                ? '(e.updatedAt >= :since OR (e.updatedAt IS NULL AND e.createdAt >= :since))'
                : 'e.updatedAt >= :since');
            $qb->setParameter('since', $this->sinceWallClock($since));
        }
    }

    public function resolveClass(string $entity): ?string
    {
        return match ($entity) {
            'orders', 'refunds' => OrderInterface::class,
            'customers' => CustomerInterface::class,
            'products' => ProductInterface::class,
            'categories' => TaxonInterface::class,
            'coupons' => PromotionInterface::class,
            default => null,
        };
    }

    private function hasField(string $class, string $field): bool
    {
        try {
            $metadata = $this->em->getClassMetadata($class);

            return $metadata->hasField($field);
        } catch (\Throwable) {
            return false;
        }
    }

    private function sinceWallClock(string $since): \DateTimeImmutable
    {
        $received = new \DateTimeImmutable($since);
        $local = $received->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $local->format('Y-m-d H:i:s') < $received->format('Y-m-d H:i:s') ? $local : $received;
    }
}
