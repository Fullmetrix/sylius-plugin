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
     * @return array{status: int, body: string, error: ?string}
     */
    public function post(string $url, string $body, array $headers, bool $fireAndForget = false): array
    {
        $ch = curl_init($url);
        if (false === $ch) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init_failed'];
        }

        $connect = $this->connectTimeoutMs;
        $total = $this->totalTimeoutMs;
        if ($fireAndForget && \function_exists('fastcgi_finish_request')) {
            $connect = 2000;
            $total = 3000;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Content-Type: application/json';
        $headerLines[] = 'Content-Length: ' . \strlen($body);

        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_NOSIGNAL => 1,
            \CURLOPT_CONNECTTIMEOUT_MS => $connect,
            \CURLOPT_TIMEOUT_MS => $total,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($response) ? $response : '',
            'error' => $error,
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: ?string}
     */
    public function get(string $url, array $headers): array
    {
        $ch = curl_init($url);
        if (false === $ch) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init_failed'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            \CURLOPT_HTTPGET => true,
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_NOSIGNAL => 1,
            \CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            \CURLOPT_TIMEOUT_MS => max($this->totalTimeoutMs, 2000),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($response) ? $response : '',
            'error' => $error,
        ];
    }

    public static function finishRequestEarly(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }
}
