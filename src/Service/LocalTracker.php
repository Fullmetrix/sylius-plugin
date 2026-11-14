<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class LocalTracker
{
    public const DEFAULT_ORIGIN = 'https://fullmetrix.com';

    public const FALLBACK_PATH = '/assets/js/s.js?k=';

    public const PLATFORM = 'sy';

    public const TAG_KEY_PATTERN = '/^[A-Z2-7]{20}$/';

    public const SEND_ORIGIN_PATTERN = '#^https://([a-z0-9-]{1,20})\.([a-z0-9.-]+)$#';

    public const VERSION_PATTERN = '/^[0-9a-f]{12}$/';

    public const PUBLIC_PATH_PATTERN = '#^(/[A-Za-z0-9_-]+)+$#';

    public const SCRIPT_PATH_PATTERN = '#^(/[A-Za-z0-9_-]+)+/[A-Za-z0-9_-]{1,64}\.js$#';

    public const STALE_AFTER_SECONDS = 86400;

    public const KEEPER_PATH = '/kvo/k';

    public const LEGACY_KEEPER_PATH = '/_fm/keep';

    public const KEEPER_MAX_DAYS = 390;

    public const EXCLUSION_ATTRIBUTES = [
        'data-no-optimize' => '1',
        'data-no-defer' => '1',
        'data-no-minify' => '1',
        'data-cfasync' => 'false',
        'nowprocket' => '1',
        'data-noptimize' => '1',
        'data-ao-skip' => '1',
        'data-pagespeed-no-defer' => '1',
        'data-wpfc-render' => 'false',
    ];

    private const ORIGIN_SHAPE = '#^https://[a-z0-9.-]{1,253}$#';

    /** @return array{path: string}|null */
    public static function usable(mixed $state, int $now): ?array
    {
        if (!\is_array($state) || true === ($state['remote'] ?? false)) {
            return null;
        }

        $path = $state['path'] ?? null;
        $checkedAt = $state['checkedAt'] ?? null;
        if (!\is_string($path) || 1 !== preg_match(self::SCRIPT_PATH_PATTERN, $path)) {
            return null;
        }
        if (!\is_int($checkedAt) || $checkedAt < $now - self::STALE_AFTER_SECONDS || $checkedAt > $now + 300) {
            return null;
        }

        return ['path' => $path];
    }

    /** @return array{enabled: bool, maxAgeDays: int} */
    public static function keeper(mixed $value): array
    {
        if (!\is_array($value)) {
            return ['enabled' => true, 'maxAgeDays' => self::KEEPER_MAX_DAYS];
        }
        $days = $value['maxAgeDays'] ?? null;

        return [
            'enabled' => \is_bool($value['enabled'] ?? null) ? $value['enabled'] : true,
            'maxAgeDays' => \is_int($days) ? max(1, min(self::KEEPER_MAX_DAYS, $days)) : self::KEEPER_MAX_DAYS,
        ];
    }

    /** @return array{tagKey: string, origin: string} */
    public static function tag(mixed $value): array
    {
        $tagKey = \is_array($value) ? ($value['tagKey'] ?? null) : null;
        $origin = \is_array($value) ? ($value['origin'] ?? null) : null;

        return [
            'tagKey' => \is_string($tagKey) && 1 === preg_match(self::TAG_KEY_PATTERN, $tagKey) ? $tagKey : '',
            'origin' => \is_string($origin) && 1 === preg_match(self::ORIGIN_SHAPE, $origin) ? $origin : self::DEFAULT_ORIGIN,
        ];
    }

    public static function acceptedSendOrigin(mixed $candidate, string $domain): string
    {
        if (!\is_string($candidate) || self::DEFAULT_ORIGIN === $candidate) {
            return self::DEFAULT_ORIGIN;
        }
        if ('' === $domain || 1 !== preg_match(self::SEND_ORIGIN_PATTERN, $candidate, $match) || $match[2] !== $domain) {
            return self::DEFAULT_ORIGIN;
        }

        return $candidate;
    }

    public static function declaredDomain(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $host = (string) preg_replace('/:[0-9]+$/', '', $host);
        if ('' === $host) {
            return '';
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function fallbackUrl(string $key): string
    {
        return self::DEFAULT_ORIGIN . self::FALLBACK_PATH . rawurlencode($key);
    }

    public static function trackerDisabled(mixed $pluginConfig): bool
    {
        $data = \is_array($pluginConfig) ? ($pluginConfig['data'] ?? null) : null;

        return \is_array($data) && false === ($data['trackerEnabled'] ?? null);
    }
}
