<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\DBAL\Types\Types;
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

    public function __construct(private readonly PluginTable $tables)
    {
    }

    public function log(string $type, string $message, ?array $details = null): void
    {
        if (!$this->tables->canWrite()) {
            return;
        }

        try {
            $this->write($type, mb_substr($message, 0, self::MAX_MESSAGE_LEN), $details);
        } catch (\Throwable) {
        }
    }

    public function clear(): void
    {
        $this->tables->connection()->executeStatement(sprintf('DELETE FROM %s', $this->table()));
    }

    private function write(string $type, string $message, ?array $details): void
    {
        $connection = $this->tables->connection();
        $fingerprint = $this->fingerprint($type, $message, $details);
        $now = new \DateTimeImmutable();
        $threshold = $now->modify('-' . self::DEDUP_WINDOW_SECONDS . ' seconds');

        $bumped = $connection->executeStatement(
            sprintf(
                'UPDATE %1$s SET %2$s = %2$s + 1, %3$s = ? WHERE %4$s = ? AND %3$s >= ?',
                $this->table(),
                $this->column('count'),
                $this->column('createdAt'),
                $this->column('fingerprint'),
            ),
            [$now, $fingerprint, $threshold],
            [Types::DATETIME_IMMUTABLE, Types::STRING, Types::DATETIME_IMMUTABLE],
        );
        if ($bumped > 0) {
            return;
        }

        $columns = ['type', 'message', 'details', 'fingerprint', 'count', 'createdAt'];
        $values = [$type, $message, $details, $fingerprint, 1, $now];
        $types = [Types::STRING, Types::STRING, Types::JSON, Types::STRING, Types::INTEGER, Types::DATETIME_IMMUTABLE];
        $id = $this->tables->nextId(FullmetrixLog::class);
        if (null !== $id) {
            array_unshift($columns, 'id');
            array_unshift($values, $id);
            array_unshift($types, Types::INTEGER);
        }

        $connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table(),
                implode(', ', array_map(fn (string $field): string => $this->column($field), $columns)),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            $values,
            $types,
        );

        $this->trim();
    }

    private function trim(): void
    {
        $connection = $this->tables->connection();
        $id = $this->column('id');
        $excess = $connection->fetchFirstColumn($connection->getDatabasePlatform()->modifyLimitQuery(
            sprintf('SELECT %s FROM %s ORDER BY %s DESC, %s DESC', $id, $this->table(), $this->column('createdAt'), $id),
            1000,
            self::MAX_ENTRIES,
        ));

        foreach ($excess as $excessId) {
            $connection->executeStatement(
                sprintf('DELETE FROM %s WHERE %s = ?', $this->table(), $id),
                [(int) $excessId],
                [Types::INTEGER],
            );
        }
    }

    private function table(): string
    {
        return $this->tables->table(FullmetrixLog::class);
    }

    private function column(string $field): string
    {
        return $this->tables->column(FullmetrixLog::class, $field);
    }

    private function fingerprint(string $type, string $message, ?array $details): string
    {
        $normalized = $type . '|' . $message . '|' . md5(json_encode($details ?? [], \JSON_UNESCAPED_UNICODE) ?: '');

        return substr(md5($normalized), 0, 32);
    }
}
