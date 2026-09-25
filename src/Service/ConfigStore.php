<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Fullmetrix\SyliusPlugin\Entity\FullmetrixConfig;
use Symfony\Contracts\Service\ResetInterface;

final class ConfigStore implements ResetInterface
{
    public const KEY_CONNECTION_CODE = 'connection_code';

    public const KEY_CONNECTION_SECRET = 'connection_secret';

    public const KEY_REGISTERED = 'registered';

    public const KEY_WEBHOOKS_ENABLED = 'webhooks_enabled';

    public const KEY_API_BASE = 'api_base';

    public const KEY_LAST_SYNC = 'last_sync';

    public const KEY_EXPORT_COUNT = 'export_count';

    public const KEY_SYNC_IN_PROGRESS = 'sync_in_progress';

    public const KEY_PLUGIN_CONFIG = 'plugin_config';

    public const KEY_STORE_CANONICAL_ID = 'store_canonical_id';

    public const KEY_DELIVERY_PAUSED_UNTIL = 'delivery_paused_until';

    public const KEY_DELIVERY_FAILURES = 'delivery_failures';

    private const SYNC_STALE_AFTER_SECONDS = 600;

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(private readonly PluginTable $tables)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->load();

        return $values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): bool
    {
        $encoded = (null === $value) ? null : json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $encoded || !$this->tables->canWrite()) {
            return false;
        }

        try {
            $this->write($key, $encoded);
        } catch (\Throwable) {
            return false;
        }

        if (null !== $this->values) {
            $this->values[$key] = $value;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        if (!$this->tables->canWrite()) {
            return false;
        }

        try {
            $this->tables->connection()->executeStatement(
                sprintf('DELETE FROM %s WHERE %s = ?', $this->table(), $this->column('configKey')),
                [$key],
            );
        } catch (\Throwable) {
            return false;
        }

        if (null !== $this->values) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function reset(): void
    {
        $this->values = null;
    }

    public function isSyncInProgress(): bool
    {
        $value = $this->get(self::KEY_SYNC_IN_PROGRESS);
        if (!\is_array($value)) {
            return false;
        }

        $startedAt = (int) ($value['started_at'] ?? 0);
        if ($startedAt <= 0 || (time() - $startedAt) > self::SYNC_STALE_AFTER_SECONDS) {
            $this->set(self::KEY_SYNC_IN_PROGRESS, null);

            return false;
        }

        return true;
    }

    public function isRegistered(): bool
    {
        return true === $this->get(self::KEY_REGISTERED, false) &&
            !empty($this->get(self::KEY_CONNECTION_CODE)) &&
            !empty($this->get(self::KEY_CONNECTION_SECRET));
    }

    public function isActive(): bool
    {
        return $this->isRegistered() &&
            true === $this->get(self::KEY_WEBHOOKS_ENABLED, true);
    }

    public function getConnectionCode(): ?string
    {
        $value = $this->get(self::KEY_CONNECTION_CODE);

        return \is_string($value) ? $value : null;
    }

    public function getConnectionSecret(): ?string
    {
        $value = $this->get(self::KEY_CONNECTION_SECRET);

        return \is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (null !== $this->values) {
            return $this->values;
        }

        if (!$this->tables->canQuery()) {
            return [];
        }

        try {
            $rows = $this->tables->connection()->fetchAllKeyValue(
                sprintf('SELECT %s, %s FROM %s', $this->column('configKey'), $this->column('value'), $this->table()),
            );
        } catch (\Throwable) {
            return $this->values = [];
        }

        $values = [];
        foreach ($rows as $key => $raw) {
            if (null === $raw) {
                continue;
            }
            $decoded = json_decode((string) $raw, true);
            $value = (\JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw;
            if (null !== $value) {
                $values[(string) $key] = $value;
            }
        }

        return $this->values = $values;
    }

    private function write(string $key, ?string $encoded): void
    {
        $connection = $this->tables->connection();
        $now = new \DateTimeImmutable();
        $update = sprintf(
            'UPDATE %s SET %s = ?, %s = ? WHERE %s = ?',
            $this->table(),
            $this->column('value'),
            $this->column('updatedAt'),
            $this->column('configKey'),
        );
        $updateTypes = [Types::TEXT, Types::DATETIME_IMMUTABLE, Types::STRING];

        if ($connection->executeStatement($update, [$encoded, $now, $key], $updateTypes) > 0) {
            return;
        }

        try {
            $connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (%s, %s, %s) VALUES (?, ?, ?)',
                    $this->table(),
                    $this->column('configKey'),
                    $this->column('value'),
                    $this->column('updatedAt'),
                ),
                [$key, $encoded, $now],
                [Types::STRING, Types::TEXT, Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            $connection->executeStatement($update, [$encoded, $now, $key], $updateTypes);
        }
    }

    private function table(): string
    {
        return $this->tables->table(FullmetrixConfig::class);
    }

    private function column(string $field): string
    {
        return $this->tables->column(FullmetrixConfig::class, $field);
    }
}
