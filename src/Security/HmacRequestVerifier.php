<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Security;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\HmacSigner;
use Symfony\Component\HttpFoundation\Request;

final class HmacRequestVerifier
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly HmacSigner $signer,
    ) {
    }

    public function verify(Request $request): bool
    {
        if (!$this->config->isRegistered()) {
            return false;
        }

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
