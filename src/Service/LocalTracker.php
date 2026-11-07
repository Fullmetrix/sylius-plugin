<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class LocalTracker
{
    public const ORIGIN_PATTERN = '#^https://([a-z0-9-]+\.)?fullmetrix\.com$#';

    public const VERSION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public const PUBLIC_PATH_PATTERN = '#^(/[A-Za-z0-9_-]+)+$#';

    public const SCRIPT_PATH_PATTERN = '#^(/[A-Za-z0-9_-]+)+/fm-[A-Za-z0-9_-]{1,64}\.js$#';

    public const STALE_AFTER_SECONDS = 86400;

    public const KEEPER_PATH = '/_fm/keep';

    public const KEEPER_MAX_DAYS = 390;

    public const TAG_ATTRIBUTE_NAMES = ['type', 'data-cookieconsent', 'data-category', 'data-cookiescript', 'data-cmp-ab'];

    public const TAG_ATTRIBUTE_VALUE_PATTERN = '#^[A-Za-z0-9 _./-]{0,64}$#';

    /** @return array{path: string, origin: string}|null */
    public static function usable(mixed $state, int $now): ?array
    {
        if (!\is_array($state) || true === ($state['remote'] ?? false)) {
            return null;
        }

        $path = $state['path'] ?? null;
        $origin = $state['origin'] ?? null;
        $checkedAt = $state['checkedAt'] ?? null;
        if (!\is_string($path) || 1 !== preg_match(self::SCRIPT_PATH_PATTERN, $path)) {
            return null;
        }
        if (!\is_string($origin) || 1 !== preg_match(self::ORIGIN_PATTERN, $origin)) {
            return null;
        }
        if (!\is_int($checkedAt) || $checkedAt < $now - self::STALE_AFTER_SECONDS || $checkedAt > $now + 300) {
            return null;
        }

        return ['path' => $path, 'origin' => $origin];
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

    /** @return array<string, string> */
    public static function tagAttributes(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $attributes = [];
        foreach ($value as $name => $attribute) {
            if (\in_array($name, self::TAG_ATTRIBUTE_NAMES, true) && \is_string($attribute)
                && 1 === preg_match(self::TAG_ATTRIBUTE_VALUE_PATTERN, $attribute)) {
                $attributes[$name] = $attribute;
            }
        }

        return $attributes;
    }

    public static function trackerDisabled(mixed $pluginConfig): bool
    {
        $data = \is_array($pluginConfig) ? ($pluginConfig['data'] ?? null) : null;

        return \is_array($data) && false === ($data['trackerEnabled'] ?? null);
    }
}
