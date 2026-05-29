<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class HmacSigner
{
    public const HEADER_CONNECTION_CODE = 'X-Fullmetrix-Connection-Code';
    public const HEADER_SIGNATURE = 'X-Fullmetrix-Signature';
    public const HEADER_TIMESTAMP = 'X-Fullmetrix-Timestamp';
    public const HEADER_PLUGIN_VERSION = 'X-Fullmetrix-Plugin-Version';

    private const TIMESTAMP_TOLERANCE_MS = 300_000;

    public function __construct(private readonly string $pluginVersion)
    {
    }

    public function sign(string $secret, string $body, int $timestampMs): string
    {
        return hash_hmac('sha256', $timestampMs . '.' . $body, $secret);
    }

    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * @return array<string, string>
     */
    public function buildHeaders(string $secret, string $connectionCode, string $body = ''): array
    {
        $timestamp = $this->nowMs();
        $signature = $this->sign($secret, $body, $timestamp);

        return [
            self::HEADER_CONNECTION_CODE => $connectionCode,
            self::HEADER_SIGNATURE => $signature,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_PLUGIN_VERSION => $this->pluginVersion,
        ];
    }

    public function verify(string $secret, string $body, string $signature, int $timestampMs): bool
    {
        $now = $this->nowMs();
        if (abs($now - $timestampMs) > self::TIMESTAMP_TOLERANCE_MS) {
            return false;
        }

        $expected = $this->sign($secret, $body, $timestampMs);

        return hash_equals($expected, $signature);
    }
}
