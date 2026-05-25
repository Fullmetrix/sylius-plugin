<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Shop;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CartRecoveryController
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly CartContextInterface $cartContext,
        private readonly RepositoryInterface $productVariantRepository,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $itemQuantityModifier,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function recover(Request $request): Response
    {
        $encoded = (string) $request->query->get('fm_cart', '');
        $signature = (string) $request->query->get('fm_cart_sig', '');
        $secret = $this->config->getConnectionSecret();

        if ('' === $encoded || '' === $signature || null === $secret) {
            return $this->redirectHome();
        }

        $expected = hash_hmac('sha256', $encoded, $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->redirectHome();
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $json) {
            return $this->redirectHome();
        }
        $payload = json_decode($json, true);
        if (!\is_array($payload) || !isset($payload['items']) || !\is_array($payload['items'])) {
            return $this->redirectHome();
        }

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface) {
            return $this->redirectHome();
        }

        foreach ($payload['items'] as $item) {
            $variantId = $item['v'] ?? null;
            $quantity = (int) ($item['q'] ?? 1);
            if (null === $variantId || $quantity <= 0) {
                continue;
            }

            $variant = $this->productVariantRepository->find($variantId);
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            /** @var OrderItemInterface $orderItem */
            $orderItem = $this->orderItemFactory->createNew();
            $orderItem->setVariant($variant);
            $this->itemQuantityModifier->modify($orderItem, $quantity);
            $cart->addItem($orderItem);
        }

        try {
            return new RedirectResponse($this->urls->generate('sylius_shop_cart_summary'));
        } catch (\Throwable) {
            return $this->redirectHome();
        }
    }

    private function redirectHome(): RedirectResponse
    {
        try {
            return new RedirectResponse($this->urls->generate('sylius_shop_homepage'));
        } catch (\Throwable) {
            return new RedirectResponse('/');
        }
    }
}
