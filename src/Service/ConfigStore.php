<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Entity\FullmetrixConfig;

final class ConfigStore
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

    private const SYNC_STALE_AFTER_SECONDS = 600;

    private array $cache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?? $default;
        }

        $row = $this->em->getRepository(FullmetrixConfig::class)->find($key);
        if (null === $row) {
            $this->cache[$key] = null;

            return $default;
        }

        $raw = $row->getValue();
        if (null === $raw) {
            $this->cache[$key] = null;

            return $default;
        }

        $decoded = json_decode($raw, true);
        $value = (\JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw;
        $this->cache[$key] = $value;

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $repo = $this->em->getRepository(FullmetrixConfig::class);
        $row = $repo->find($key);
        if (null === $row) {
            $row = new FullmetrixConfig($key);
            $this->em->persist($row);
        }

        $encoded = (null === $value) ? null : json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $row->setValue($encoded);
        $this->em->flush();

        $this->cache[$key] = $value;
    }

    public function delete(string $key): void
    {
        $row = $this->em->getRepository(FullmetrixConfig::class)->find($key);
        if (null !== $row) {
            $this->em->remove($row);
            $this->em->flush();
        }

        unset($this->cache[$key]);
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
}
