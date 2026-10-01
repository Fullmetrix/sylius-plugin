<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Security;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\HmacSigner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HmacRequestVerifier
{
    public const NONCE_ATTRIBUTE = '_fullmetrix_nonce';

    private const COMMAND_NONCES_KEPT = 64;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HmacSigner $signer,
        private readonly Signature $signature,
        private readonly bool $signatureV1,
    ) {
    }

    public function authorize(Request $request, string $operation, bool $command = false): ?Response
    {
        if (!$this->config->isRegistered()) {
            return $this->deny(Response::HTTP_UNAUTHORIZED, 'not_configured');
        }
        if ('2' === $request->headers->get(Signature::HEADER_VERSION)) {
            return $this->authorizeV2($request, $operation, $command);
        }
        if (!$this->signatureV1) {
            return $this->deny(Response::HTTP_UNAUTHORIZED, 'version');
        }

        return $this->verify($request) ? null : $this->deny(Response::HTTP_UNAUTHORIZED, null);
    }

    private function authorizeV2(Request $request, string $operation, bool $command): ?Response
    {
        $query = $this->signature->rawQueryString(
            (string) $request->server->get('REQUEST_URI', ''),
            (string) $request->server->get('QUERY_STRING', ''),
        );
        $verdict = $this->signature->verifyRequest(
            (string) $this->config->getConnectionSecret(),
            (string) $this->config->getConnectionCode(),
            [
                'version' => (string) $request->headers->get(Signature::HEADER_VERSION),
                'code' => (string) $request->headers->get(Signature::HEADER_CODE),
                'timestamp' => (string) $request->headers->get(Signature::HEADER_TIMESTAMP),
                'nonce' => (string) $request->headers->get(Signature::HEADER_NONCE),
                'signature' => (string) $request->headers->get(Signature::HEADER_SIGNATURE),
            ],
            $request->getMethod(),
            $query,
            (string) $request->getContent(),
            $operation,
        );
        if (null !== $verdict['nonce']) {
            $request->attributes->set(self::NONCE_ATTRIBUTE, $verdict['nonce']);
        }
        if (!$verdict['ok']) {
            return $this->deny(Response::HTTP_UNAUTHORIZED, $verdict['reason']);
        }
        if ($command) {
            $seen = $this->config->get(ConfigStore::KEY_COMMAND_NONCES, []);
            $seen = \is_array($seen) ? $seen : [];
            if (\in_array($verdict['nonce'], $seen, true)) {
                return $this->deny(Response::HTTP_CONFLICT, 'replay');
            }
            $seen[] = $verdict['nonce'];
            if (!$this->config->set(ConfigStore::KEY_COMMAND_NONCES, array_values(\array_slice($seen, -self::COMMAND_NONCES_KEPT)))) {
                return $this->deny(Response::HTTP_SERVICE_UNAVAILABLE, 'nonce_store');
            }
        }

        return null;
    }

    private function deny(int $status, ?string $reason): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => match ($status) {
                Response::HTTP_CONFLICT => 'replayed_request',
                Response::HTTP_SERVICE_UNAVAILABLE => 'unavailable',
                default => 'unauthorized',
            },
            'reason' => $reason,
            'server_time' => time(),
        ], $status);
    }

    private function verify(Request $request): bool
    {
        $code = $request->headers->get(HmacSigner::HEADER_CONNECTION_CODE);
        $signature = $request->headers->get(HmacSigner::HEADER_SIGNATURE);
        $timestamp = $request->headers->get(HmacSigner::HEADER_TIMESTAMP);

        if (null === $code || null === $signature || null === $timestamp) {
            return false;
        }

        if ($code !== $this->config->getConnectionCode()) {
            return false;
        }

        $secret = $this->config->getConnectionSecret();
        if (null === $secret) {
            return false;
        }

        $body = $request->isMethod('POST') ? (string) $request->getContent() : '';

        return $this->signer->verify($secret, $body, $signature, (int) $timestamp);
    }
}
