<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

final class PluginTable
{
    /** @var array<string, array{table: string, columns: array<string, string>}> */
    private array $resolved = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function connection(): Connection
    {
        return $this->em->getConnection();
    }

    public function canQuery(): bool
    {
        try {
            $connection = $this->connection();

            return !($connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform);
        } catch (\Throwable) {
            return false;
        }
    }

    public function canWrite(): bool
    {
        try {
            return !$this->connection()->isTransactionActive();
        } catch (\Throwable) {
            return false;
        }
    }

    public function nextId(string $entityClass): ?int
    {
        $metadata = $this->em->getClassMetadata($entityClass);
        $connection = $this->connection();
        $platform = $connection->getDatabasePlatform();
        $quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();

        if ($metadata->isIdGeneratorSequence()) {
            $sequence = $quoteStrategy->getSequenceName($metadata->sequenceGeneratorDefinition, $metadata, $platform);

            return (int) $connection->fetchOne($platform->getSequenceNextValSQL($sequence));
        }
        if (!$platform instanceof PostgreSQLPlatform) {
            return null;
        }

        $table = $metadata->getTableName();
        $column = $metadata->getSingleIdentifierColumnName();

        return (int) $connection->fetchOne(
            'SELECT nextval(COALESCE(pg_get_serial_sequence(?, ?), ?))',
            [$table, $column, $table . '_' . $column . '_seq'],
        );
    }

    public function table(string $entityClass): string
    {
        return $this->resolve($entityClass)['table'];
    }

    public function column(string $entityClass, string $field): string
    {
        $columns = $this->resolve($entityClass)['columns'];
        if (!isset($columns[$field])) {
            throw new \InvalidArgumentException('Unknown field ' . $field);
        }

        return $columns[$field];
    }

    /** @return array{table: string, columns: array<string, string>} */
    private function resolve(string $entityClass): array
    {
        if (isset($this->resolved[$entityClass])) {
            return $this->resolved[$entityClass];
        }

        $metadata = $this->em->getClassMetadata($entityClass);
        $platform = $this->connection()->getDatabasePlatform();
        $quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();

        $columns = [];
        foreach ($metadata->getFieldNames() as $field) {
            $columns[$field] = $quoteStrategy->getColumnName($field, $metadata, $platform);
        }

        return $this->resolved[$entityClass] = [
            'table' => $quoteStrategy->getTableName($metadata, $platform),
            'columns' => $columns,
        ];
    }
}
