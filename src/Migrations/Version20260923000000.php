<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923000000 extends AbstractMigration
{
    private const LEGACY_COLUMNS = ['config_key' => 'configKey', 'updated_at' => 'updatedAt'];

    public function getDescription(): string
    {
        return 'Fullmetrix plugin tables (fullmetrix_config, fullmetrix_log)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('fullmetrix_config')) {
            $this->renameLegacyColumns($schema->getTable('fullmetrix_config'));
        } else {
            $config = $schema->createTable('fullmetrix_config');
            $config->addColumn('configKey', Types::STRING, ['length' => 64]);
            $config->addColumn('value', Types::TEXT, ['notnull' => false]);
            $config->addColumn('updatedAt', Types::DATETIME_IMMUTABLE);
            $config->setPrimaryKey(['configKey']);
        }

        if (!$schema->hasTable('fullmetrix_log')) {
            $sequence = $this->usesSequences();
            $log = $schema->createTable('fullmetrix_log');
            $log->addColumn('id', Types::INTEGER, ['autoincrement' => !$sequence]);
            $log->addColumn('type', Types::STRING, ['length' => 32]);
            $log->addColumn('message', Types::STRING, ['length' => 256]);
            $log->addColumn('details', Types::JSON, ['notnull' => false]);
            $log->addColumn('fingerprint', Types::STRING, ['length' => 32]);
            $log->addColumn('count', Types::INTEGER);
            $log->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $log->setPrimaryKey(['id']);
            $log->addIndex(['fingerprint', 'created_at'], 'idx_fmtx_log_fingerprint');
            $log->addIndex(['created_at'], 'idx_fmtx_log_created');
            if ($sequence && !$schema->hasSequence('fullmetrix_log_id_seq')) {
                $schema->createSequence('fullmetrix_log_id_seq');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('fullmetrix_log')) {
            $schema->dropTable('fullmetrix_log');
        }
        if ($schema->hasTable('fullmetrix_config')) {
            $schema->dropTable('fullmetrix_config');
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function usesSequences(): bool
    {
        return $this->platform instanceof PostgreSQLPlatform && method_exists($this->platform, 'getIdentitySequenceName');
    }

    private function renameLegacyColumns(Table $table): void
    {
        foreach (self::LEGACY_COLUMNS as $legacy => $current) {
            if (!$table->hasColumn($legacy) || $table->hasColumn($current)) {
                continue;
            }

            $sql = $this->renameColumnSql($legacy, $current);
            if (null !== $sql) {
                $this->addSql($sql);
            }
        }
    }

    private function renameColumnSql(string $legacy, string $current): ?string
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            return sprintf('ALTER TABLE fullmetrix_config RENAME COLUMN %s TO %s', $legacy, $current);
        }
        if (!$this->platform instanceof AbstractMySQLPlatform) {
            return null;
        }

        $column = $this->connection->fetchAssociative(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['fullmetrix_config', $legacy],
        );
        if (false === $column) {
            return null;
        }

        $definition = (string) $column['COLUMN_TYPE'];
        if (null !== $column['COLLATION_NAME']) {
            $definition .= ' COLLATE ' . $column['COLLATION_NAME'];
        }
        $definition .= 'YES' === $column['IS_NULLABLE'] ? ' NULL' : ' NOT NULL';
        if (null !== $column['COLUMN_DEFAULT']) {
            $definition .= ' DEFAULT ' . $this->connection->quote((string) $column['COLUMN_DEFAULT']);
        }
        if ('' !== (string) $column['COLUMN_COMMENT']) {
            $definition .= ' COMMENT ' . $this->connection->quote((string) $column['COLUMN_COMMENT']);
        }

        return sprintf('ALTER TABLE fullmetrix_config CHANGE %s %s %s', $legacy, $current, $definition);
    }
}
