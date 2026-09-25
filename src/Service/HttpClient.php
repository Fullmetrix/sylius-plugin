<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

final class HttpClient
{
    public function __construct(
        private readonly int $connectTimeoutMs,
        private readonly int $totalTimeoutMs,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: ?string, unreachable: bool}
     */
    public function post(
        string $url,
        string $body,
        array $headers,
        bool $fireAndForget = false,
        ?int $connectTimeoutMs = null,
        ?int $totalTimeoutMs = null,
        ?int $maxTotalMs = null,
    ): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Content-Type: application/json';
        $headerLines[] = 'Content-Length: ' . \strlen($body);

        [$connect, $total] = $this->timeouts(
            $connectTimeoutMs ?? $this->connectTimeoutMs,
            $totalTimeoutMs ?? $this->totalTimeoutMs,
            $fireAndForget,
            $maxTotalMs,
        );

        return $this->execute($url, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headerLines,
        ], $connect, $total);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: ?string, unreachable: bool}
     */
    public function get(string $url, array $headers, bool $fireAndForget = false, ?int $maxTotalMs = null): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        [$connect, $total] = $this->timeouts(
            $this->connectTimeoutMs,
            max($this->totalTimeoutMs, 2000),
            $fireAndForget,
            $maxTotalMs,
        );

        return $this->execute($url, [
            \CURLOPT_HTTPGET => true,
            \CURLOPT_HTTPHEADER => $headerLines,
        ], $connect, $total);
    }

    /** @return array{0: int, 1: int} */
    private function timeouts(int $connect, int $total, bool $fireAndForget, ?int $maxTotalMs): array
    {
        if ($fireAndForget && \function_exists('fastcgi_finish_request')) {
            $connect = 1000;
            $total = 3000;
        }
        if (null !== $maxTotalMs) {
            $total = max(1, min($total, $maxTotalMs));
            $connect = min($connect, $total);
        }

        return [$connect, $total];
    }

    /**
     * @param array<int, mixed> $options
     *
     * @return array{status: int, body: string, error: ?string, unreachable: bool}
     */
    private function execute(string $url, array $options, int $connect, int $total): array
    {
        $ch = curl_init($url);
        if (false === $ch) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init_failed', 'unreachable' => false];
        }

        curl_setopt_array($ch, $options + [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_CONNECTTIMEOUT_MS => $connect,
            \CURLOPT_TIMEOUT_MS => $total,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $connected = (float) curl_getinfo($ch, \CURLINFO_CONNECT_TIME) > 0.0;

        return [
            'status' => $status,
            'body' => \is_string($response) ? $response : '',
            'error' => $error,
            'unreachable' => 0 === $status && self::isConnectionFailure($errno, $connected),
        ];
    }

    private static function isConnectionFailure(int $errno, bool $connected): bool
    {
        return match ($errno) {
            \CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT => true,
            \CURLE_OPERATION_TIMEDOUT => !$connected,
            default => false,
        };
    }

    public static function finishRequestEarly(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }
}
