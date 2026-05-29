<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CartSerializer
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly HmacSigner $signer,
        private readonly ConfigStore $config,
    ) {
    }

    public function serialize(OrderInterface $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }
            $variant = $item->getVariant();
            $product = $variant?->getProduct();

            $imageUrl = null;
            if (null !== $product) {
                foreach ($product->getImages() as $image) {
                    $imageUrl = $image->getPath();
                    break;
                }
            }

            $items[] = [
                'product_id' => $product?->getId(),
                'variation_id' => $variant?->getId(),
                'variant_code' => $variant?->getCode(),
                'name' => $item->getProductName(),
                'quantity' => $item->getQuantity(),
                'price' => $this->money($item->getUnitPrice()),
                'line_total' => $this->money($item->getTotal()),
                'sku' => $variant?->getCode(),
                'image_url' => $imageUrl,
                'url' => null,
            ];
        }

        $couponCodes = [];
        $coupon = $cart->getPromotionCoupon();
        if (null !== $coupon) {
            $couponCodes[] = $coupon->getCode();
        }

        return [
            'currency' => (string) $cart->getCurrencyCode(),
            'total' => $this->money($cart->getTotal()),
            'subtotal' => $this->money($cart->getItemsTotal()),
            'discount_total' => $this->money($this->discountTotal($cart)),
            'shipping_total' => $this->money($cart->getShippingTotal()),
            'tax_total' => $this->money($cart->getTaxTotal()),
            'coupon_codes' => $couponCodes,
            'item_count' => $cart->getTotalQuantity(),
            'items' => $items,
            'recovery_url' => $this->buildRecoveryUrl($cart),
        ];
    }

    public function buildRecoveryUrl(OrderInterface $cart): ?string
    {
        $secret = $this->config->getConnectionSecret();
        if (null === $secret) {
            return null;
        }

        $payload = ['items' => [], 'c' => []];
        foreach ($cart->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }
            $variant = $item->getVariant();
            $product = $variant?->getProduct();
            if (null === $product?->getId()) {
                continue;
            }
            $payload['items'][] = [
                'id' => $product->getId(),
                'v' => $variant?->getId(),
                'q' => $item->getQuantity(),
            ];
        }
        $coupon = $cart->getPromotionCoupon();
        if (null !== $coupon) {
            $payload['c'][] = $coupon->getCode();
        }

        $json = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            return null;
        }
        $encoded = strtr(base64_encode($json), '+/', '-_');
        $sig = hash_hmac('sha256', $encoded, $secret);

        try {
            return $this->urls->generate('fullmetrix_shop_recover_cart', [
                'fm_cart' => $encoded,
                'fm_cart_sig' => $sig,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }

    private function discountTotal(OrderInterface $cart): int
    {
        $total = 0;
        foreach ($cart->getAdjustments() as $adjustment) {
            $type = $adjustment->getType();
            if (str_contains((string) $type, 'promotion')) {
                $total += $adjustment->getAmount();
            }
        }
        foreach ($cart->getItems() as $item) {
            foreach ($item->getAdjustments() as $adjustment) {
                $type = $adjustment->getType();
                if (str_contains((string) $type, 'promotion')) {
                    $total += $adjustment->getAmount();
                }
            }
        }

        return $total;
    }

    private function money(?int $cents): string
    {
        return number_format((null === $cents ? 0 : $cents) / 100, 2, '.', '');
    }
}
