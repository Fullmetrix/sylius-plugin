<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Entity\FullmetrixLog;

final class Logger
{
    public const TYPE_REGISTERED = 'registered';
    public const TYPE_DISCONNECTED = 'disconnected';
    public const TYPE_SYNC_START = 'sync_start';
    public const TYPE_SYNC_COMPLETE = 'sync_complete';
    public const TYPE_SYNC_ERROR = 'sync_error';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_COMMAND = 'command';

    private const MAX_ENTRIES = 30;
    private const MAX_MESSAGE_LEN = 256;
    private const DEDUP_WINDOW_SECONDS = 60;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(string $type, string $message, ?array $details = null): void
    {
        $message = mb_substr($message, 0, self::MAX_MESSAGE_LEN);
        $fingerprint = $this->fingerprint($type, $message, $details);
        $repo = $this->em->getRepository(FullmetrixLog::class);

        $threshold = (new \DateTimeImmutable())->modify('-' . self::DEDUP_WINDOW_SECONDS . ' seconds');
        $existing = $repo->createQueryBuilder('l')
            ->where('l.fingerprint = :fp')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('fp', $fingerprint)
            ->setParameter('since', $threshold)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof FullmetrixLog) {
            $existing->bumpCount();
            $this->em->flush();

            return;
        }

        $entry = new FullmetrixLog($type, $message, $details, $fingerprint);
        $this->em->persist($entry);
        $this->em->flush();

        $this->trim();
    }

    private function trim(): void
    {
        $repo = $this->em->getRepository(FullmetrixLog::class);
        $excess = $repo->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setFirstResult(self::MAX_ENTRIES)
            ->getQuery()
            ->getResult();

        if (empty($excess)) {
            return;
        }

        foreach ($excess as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
    }

    public function clear(): void
    {
        $this->em->createQueryBuilder()
            ->delete(FullmetrixLog::class, 'l')
            ->getQuery()
            ->execute();
    }

    private function fingerprint(string $type, string $message, ?array $details): string
    {
        $normalized = $type . '|' . $message . '|' . md5(json_encode($details ?? [], \JSON_UNESCAPED_UNICODE) ?: '');

        return substr(md5($normalized), 0, 32);
    }
}
