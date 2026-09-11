<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\CartLinkResolver;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Promotion\Checker\Eligibility\PromotionCouponEligibilityCheckerInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CartRecoveryController
{
    private const CART_PARAM = 'fm_cart_id';
    private const MAX_ITEMS = 25;
    private const LINK_PARAMS = [self::CART_PARAM, 'fm_cart', 'fm_cart_sig'];
    private const ROUTE_PARAMS = ['_locale', '_format', '_fragment', '_route', '_controller'];

    public function __construct(
        private readonly ConfigStore $config,
        private readonly CartLinkResolver $linkResolver,
        private readonly CartContextInterface $cartContext,
        private readonly RepositoryInterface $productVariantRepository,
        private readonly RepositoryInterface $productRepository,
        private readonly RepositoryInterface $promotionCouponRepository,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $itemQuantityModifier,
        private readonly OrderModifierInterface $orderModifier,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly PromotionCouponEligibilityCheckerInterface $couponEligibility,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function recover(Request $request): Response
    {
        $payload = $this->readPayload($request);
        if (null === $payload) {
            return $this->redirectTo('cart', $request);
        }

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface) {
            return $this->redirectTo('cart', $request);
        }

        $existing = [];
        foreach ($cart->getItems() as $item) {
            if (null !== $item->getVariant()) {
                $existing[(string) $item->getVariant()->getId()] = true;
            }
        }

        $items = \array_slice($payload['items'], 0, self::MAX_ITEMS);
        $added = false;

        foreach ($items as $item) {
            $variant = $this->findVariant($item);
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            if (isset($existing[(string) $variant->getId()])) {
                continue;
            }

            $quantity = max(1, (int) ($item['q'] ?? 1));

            /** @var OrderItemInterface $orderItem */
            $orderItem = $this->orderItemFactory->createNew();
            $orderItem->setVariant($variant);
            $this->itemQuantityModifier->modify($orderItem, $quantity);
            $this->orderModifier->addToOrder($cart, $orderItem);
            $existing[(string) $variant->getId()] = true;
            $added = true;
        }

        $couponApplied = $this->applyFirstCoupon($cart, $payload['c']);

        if ($added || $couponApplied) {
            $this->orderProcessor->process($cart);
            $this->em->persist($cart);
            $this->em->flush();
        }

        $target = ('checkout' === $payload['target']) ? 'checkout' : 'cart';

        return $this->redirectTo($target, $request);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, c: array<int, mixed>, target: string}|null
     */
    private function readPayload(Request $request): ?array
    {
        $linkId = (string) $request->query->get(self::CART_PARAM, '');
        if ('' !== $linkId) {
            return $this->linkResolver->resolve($linkId);
        }

        $encoded = (string) $request->query->get('fm_cart', '');
        $signature = (string) $request->query->get('fm_cart_sig', '');
        $secret = $this->config->getConnectionSecret();

        if ('' === $encoded || '' === $signature || null === $secret) {
            return null;
        }

        if (!hash_equals(hash_hmac('sha256', $encoded, $secret), $signature)) {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            return null;
        }

        return [
            'items' => $decoded['items'],
            'c' => \is_array($decoded['c'] ?? null) ? $decoded['c'] : [],
            'target' => \is_string($decoded['target'] ?? null) ? $decoded['target'] : 'cart',
        ];
    }

    /**
     * @param array<int, mixed> $codes
     */
    private function applyFirstCoupon(OrderInterface $cart, array $codes): bool
    {
        if (null !== $cart->getPromotionCoupon()) {
            return false;
        }

        foreach ($codes as $code) {
            if (!\is_string($code) || '' === trim($code)) {
                continue;
            }

            $coupon = $this->promotionCouponRepository->findOneBy(['code' => trim($code)]);
            if (!$coupon instanceof PromotionCouponInterface) {
                continue;
            }
            if (!$this->couponEligibility->isEligible($cart, $coupon)) {
                continue;
            }

            $cart->setPromotionCoupon($coupon);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function findVariant(array $item): ?ProductVariantInterface
    {
        $variantId = $item['v'] ?? null;
        if (null !== $variantId && '' !== (string) $variantId) {
            $variant = $this->productVariantRepository->find($variantId);
            if ($variant instanceof ProductVariantInterface) {
                return $variant;
            }
        }

        $productId = $item['id'] ?? null;
        if (null === $productId || '' === (string) $productId) {
            return null;
        }

        $product = $this->productRepository->find($productId);
        if (!$product instanceof ProductInterface) {
            return null;
        }

        $variants = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $variants[] = $variant;
            }
        }

        return 1 === \count($variants) ? $variants[0] : null;
    }

    private function redirectTo(string $target, Request $request): RedirectResponse
    {
        $params = [];
        foreach ($request->query->all() as $key => $value) {
            if (\in_array($key, self::LINK_PARAMS, true) || \in_array($key, self::ROUTE_PARAMS, true)) {
                continue;
            }
            if (\is_scalar($value)) {
                $params[$key] = (string) $value;
            }
        }

        $route = 'checkout' === $target ? 'sylius_shop_checkout_start' : 'sylius_shop_cart_summary';

        try {
            return new RedirectResponse($this->urls->generate($route, $params));
        } catch (\Throwable) {
            try {
                return new RedirectResponse($this->urls->generate('sylius_shop_cart_summary', $params));
            } catch (\Throwable) {
                return new RedirectResponse('/');
            }
        }
    }
}
