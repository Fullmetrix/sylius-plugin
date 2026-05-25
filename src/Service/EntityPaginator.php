<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
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
        if ('coupons' === $entity) {
            $qb->andWhere('e.couponBased = :cb')->setParameter('cb', true);
        }
        if (null !== $since && $this->hasUpdatedAtField($class)) {
            $qb->andWhere('e.updatedAt >= :since')->setParameter('since', new \DateTimeImmutable($since));
        }
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
    public function streamKeyset(string $entity, int $batchSize = 1000, ?string $since = null): iterable
    {
        $class = $this->resolveClass($entity);
        if (null === $class) {
            return;
        }

        $lastId = 0;
        while (true) {
            $qb = $this->em->getRepository($class)->createQueryBuilder('e')
                ->andWhere('e.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($batchSize);

            if ('coupons' === $entity) {
                $qb->andWhere('e.couponBased = :cb')->setParameter('cb', true);
            }
            if (null !== $since && $this->hasUpdatedAtField($class)) {
                $qb->andWhere('e.updatedAt >= :since')->setParameter('since', new \DateTimeImmutable($since));
            }

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
        if ('coupons' === $entity) {
            $qb->andWhere('e.couponBased = :cb')->setParameter('cb', true);
        }
        if (null !== $since && $this->hasUpdatedAtField($class)) {
            $qb->andWhere('e.updatedAt >= :since')->setParameter('since', new \DateTimeImmutable($since));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array{id: int, last_updated: int}>
     */
    public function recentlyUpdated(string $entity, int $days, int $hours, int $limit, int $offset): array
    {
        $class = $this->resolveClass($entity);
        if (null === $class || !$this->hasUpdatedAtField($class)) {
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

        if ('coupons' === $entity) {
            $qb->andWhere('e.couponBased = :cb')->setParameter('cb', true);
        }

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'last_updated' => $row['updatedAt'] instanceof \DateTimeInterface ? $row['updatedAt']->getTimestamp() : 0,
            ];
        }

        return $result;
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

    private function hasUpdatedAtField(string $class): bool
    {
        try {
            $metadata = $this->em->getClassMetadata($class);

            return $metadata->hasField('updatedAt');
        } catch (\Throwable) {
            return false;
        }
    }
}
