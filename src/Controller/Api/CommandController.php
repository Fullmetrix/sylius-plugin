<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Api;

use Fullmetrix\SyliusPlugin\Security\HmacRequestVerifier;
use Fullmetrix\SyliusPlugin\Service\CouponCommandHandler;
use Fullmetrix\SyliusPlugin\Service\Logger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommandController
{
    public function __construct(
        private readonly HmacRequestVerifier $verifier,
        private readonly CouponCommandHandler $couponHandler,
        private readonly Logger $logger,
    ) {
    }

    public function dispatch(Request $request): Response
    {
        if (!$this->verifier->verify($request)) {
            return new JsonResponse(['success' => false, 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $action = (string) ($body['action'] ?? '');
        $payload = (array) ($body['payload'] ?? []);

        $result = match (true) {
            str_starts_with($action, 'coupon.') => $this->couponHandler->handle($action, $payload),
            default => ['success' => false, 'error' => 'unknown_action'],
        };

        $this->logger->log(Logger::TYPE_COMMAND, 'Command ' . $action, [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ]);

        return new JsonResponse(
            $result,
            $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
        );
    }
}
