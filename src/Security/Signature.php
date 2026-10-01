<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Security;

final class Signature
{
    public const TOLERANCE_MS = 300000;

    public const OPERATION_PARAM = 'fm_op';

    public const HEADER_VERSION = 'X-Fullmetrix-Sig-Version';

    public const HEADER_CODE = 'X-Fullmetrix-Connection-Code';

    public const HEADER_TIMESTAMP = 'X-Fullmetrix-Timestamp';

    public const HEADER_NONCE = 'X-Fullmetrix-Nonce';

    public const HEADER_SIGNATURE = 'X-Fullmetrix-Signature';

    public const HEADER_BODY_SIGNATURE = 'X-Fullmetrix-Body-Signature';

    private const EXCLUDED_PARAMS = ['fc', 'module', 'controller', 'isolang', 'id_lang', 'rest_route'];

    public function rawQueryString(string $requestUri, string $queryString): string
    {
        $pos = strpos($requestUri, '?');

        return false !== $pos ? substr($requestUri, $pos + 1) : $queryString;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function queryPairs(string $query): array
    {
        if ('' !== $query && '?' === $query[0]) {
            $query = substr($query, 1);
        }
        $pairs = [];
        foreach (explode('&', $query) as $segment) {
            if ('' === $segment) {
                continue;
            }
            $eq = strpos($segment, '=');
            $name = false === $eq ? $segment : substr($segment, 0, $eq);
            $value = false === $eq ? '' : substr($segment, $eq + 1);
            $pairs[] = [urldecode($name), urldecode($value)];
        }

        return $pairs;
    }

    public function queryValue(string $query, string $name): ?string
    {
        foreach ($this->queryPairs($query) as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    public function hasDuplicateNames(string $query): bool
    {
        $seen = [];
        foreach ($this->queryPairs($query) as [$name]) {
            $bracket = strpos($name, '[');
            $key = strtr(false === $bracket ? $name : substr($name, 0, $bracket), ['.' => '_', ' ' => '_']);
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }

    public function canonicalQuery(string $query): string
    {
        $encoded = [];
        foreach ($this->queryPairs($query) as [$name, $value]) {
            if (\in_array($name, self::EXCLUDED_PARAMS, true)) {
                continue;
            }
            $encoded[] = [rawurlencode($name), rawurlencode($value)];
        }
        usort($encoded, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(static fn (array $pair): string => $pair[0] . '=' . $pair[1], $encoded));
    }

    public function stringToSign(string $method, string $query, string $timestamp, string $nonce, string $body): string
    {
        return "v2\n" . strtoupper($method) . "\n" . $this->canonicalQuery($query) . "\n"
            . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public function signRequest(
        string $secret,
        string $method,
        string $query,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return hash_hmac('sha256', $this->stringToSign($method, $query, $timestamp, $nonce, $body), $secret);
    }

    public function signResponse(string $secret, string $requestNonce, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $requestNonce . '.' . $timestamp . '.' . $body, $secret);
    }

    public function verifyResponse(
        string $secret,
        string $requestNonce,
        string $timestamp,
        string $bodySignature,
        string $body
    ): bool {
        if (!preg_match('/^\d{1,16}$/', $timestamp) || !preg_match('/^[a-f0-9]{64}$/', $bodySignature)) {
            return false;
        }

        return hash_equals($this->signResponse($secret, $requestNonce, $timestamp, $body), $bodySignature);
    }

    public function nowMs(): string
    {
        return sprintf('%.0f', floor(microtime(true) * 1000));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{ok: bool, reason: string|null, nonce: string|null}
     */
    public function verifyRequest(
        string $secret,
        string $code,
        array $headers,
        string $method,
        string $query,
        string $body,
        string $operation,
        ?string $nowMs = null
    ): array {
        if ('' === $secret || '' === $code) {
            return ['ok' => false, 'reason' => 'not_configured', 'nonce' => null];
        }
        if ('2' !== (string) ($headers['version'] ?? '')) {
            return ['ok' => false, 'reason' => 'version', 'nonce' => null];
        }
        if (!hash_equals($code, (string) ($headers['code'] ?? ''))) {
            return ['ok' => false, 'reason' => 'code', 'nonce' => null];
        }
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        if (!preg_match('/^\d{1,16}$/', $timestamp)
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return ['ok' => false, 'reason' => 'format', 'nonce' => null];
        }
        if ($this->hasDuplicateNames($query)) {
            return ['ok' => false, 'reason' => 'format', 'nonce' => null];
        }
        $now = (float) ($nowMs ?? $this->nowMs());
        if (abs($now - (float) $timestamp) > self::TOLERANCE_MS) {
            return ['ok' => false, 'reason' => 'expired', 'nonce' => $nonce];
        }
        if (!hash_equals($this->signRequest($secret, $method, $query, $timestamp, $nonce, $body), $signature)) {
            return ['ok' => false, 'reason' => 'signature', 'nonce' => $nonce];
        }
        if ($this->queryValue($query, self::OPERATION_PARAM) !== $operation) {
            return ['ok' => false, 'reason' => 'operation', 'nonce' => $nonce];
        }

        return ['ok' => true, 'reason' => null, 'nonce' => $nonce];
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(string $secret, string $requestNonce, string $body): array
    {
        $timestamp = $this->nowMs();

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_BODY_SIGNATURE => $this->signResponse($secret, $requestNonce, $timestamp, $body),
        ];
    }
}
