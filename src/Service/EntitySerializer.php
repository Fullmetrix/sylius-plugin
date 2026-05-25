<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;

final class EntitySerializer
{
    public function serializeOrder(OrderInterface $order): array
    {
        return [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'status' => (string) $order->getState(),
            'checkout_state' => (string) $order->getCheckoutState(),
            'payment_state' => (string) $order->getPaymentState(),
            'shipping_state' => (string) $order->getShippingState(),
            'currency' => (string) $order->getCurrencyCode(),
            'conversion_rate' => '1.0',
            'total' => $this->money($order->getTotal()),
            'subtotal' => $this->money($order->getItemsTotal()),
            'discount_total' => $this->money($this->orderDiscountTotal($order)),
            'shipping_total' => $this->money($order->getShippingTotal()),
            'total_tax' => $this->money($order->getTaxTotal()),
            'date_created' => $this->iso($order->getCreatedAt()),
            'date_modified' => $this->iso($order->getUpdatedAt()),
            'date_paid' => null,
            'date_completed' => 'fulfilled' === $order->getShippingState() ? $this->iso($order->getUpdatedAt()) : null,
            'customer_id' => $order->getCustomer()?->getId(),
            'customer_email' => $order->getCustomer()?->getEmail() ?: $order->getEmail(),
            'customer_note' => $order->getNotes(),
            'payment_method' => $this->paymentMethod($order),
            'payment_method_title' => $this->paymentMethodTitle($order),
            'billing' => $this->address($order->getBillingAddress()),
            'shipping' => $this->address($order->getShippingAddress()),
            'line_items' => $this->lineItems($order),
            'shipping_lines' => $this->shippingLines($order),
            'coupon_lines' => $this->couponLines($order),
            'fee_lines' => [],
            'tax_lines' => $this->taxLines($order),
            'payments' => $this->payments($order),
        ];
    }

    public function serializeCustomer(CustomerInterface $customer): array
    {
        return [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'phone' => $customer->getPhoneNumber(),
            'gender' => $customer->getGender(),
            'birthday' => $this->iso($customer->getBirthday()),
            'date_created' => $this->iso($customer->getCreatedAt()),
            'date_updated' => $this->iso($customer->getUpdatedAt()),
            'billing_address' => $this->address($customer->getDefaultAddress()),
            'shipping_address' => $this->address($customer->getDefaultAddress()),
        ];
    }

    public function serializeProduct(ProductInterface $product): array
    {
        $variants = [];
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $variants[] = $this->variant($variant);
            }
        }

        $categories = [];
        foreach ($product->getTaxons() as $taxon) {
            if ($taxon instanceof TaxonInterface && null !== $taxon->getId()) {
                $categories[] = $taxon->getId();
            }
        }

        $mainImage = null;
        foreach ($product->getImages() as $image) {
            $mainImage = $image->getPath();
            break;
        }

        return [
            'id' => $product->getId(),
            'name' => (string) $product->getName(),
            'code' => $product->getCode(),
            'description' => $product->getDescription(),
            'short_description' => $product->getShortDescription(),
            'slug' => $product->getSlug(),
            'status' => $product->isEnabled() ? 'publish' : 'draft',
            'featured' => false,
            'categories' => $categories,
            'image_url' => $mainImage,
            'variations' => $variants,
            'date_created' => $this->iso($product->getCreatedAt()),
            'date_updated' => $this->iso($product->getUpdatedAt()),
        ];
    }

    public function serializeCategory(TaxonInterface $taxon): array
    {
        return [
            'id' => $taxon->getId(),
            'code' => $taxon->getCode(),
            'name' => (string) $taxon->getName(),
            'slug' => $taxon->getSlug(),
            'description' => $taxon->getDescription(),
            'parent_id' => $taxon->getParent()?->getId(),
            'position' => $taxon->getPosition(),
            'date_created' => $this->iso($taxon->getCreatedAt()),
            'date_updated' => $this->iso($taxon->getUpdatedAt()),
        ];
    }

    public function serializeCoupon(PromotionInterface $promotion): array
    {
        $codes = [];
        foreach ($promotion->getCoupons() as $coupon) {
            $codes[] = [
                'code' => $coupon->getCode(),
                'usage_limit' => $coupon->getUsageLimit(),
                'per_customer_usage_limit' => $coupon->getPerCustomerUsageLimit(),
                'used' => $coupon->getUsed(),
                'expires_at' => $this->iso($coupon->getExpiresAt()),
            ];
        }

        $actions = [];
        foreach ($promotion->getActions() as $action) {
            $actions[] = [
                'type' => $action->getType(),
                'configuration' => $action->getConfiguration(),
            ];
        }

        return [
            'id' => $promotion->getId(),
            'code' => $promotion->getCode(),
            'name' => $promotion->getName(),
            'description' => $promotion->getDescription(),
            'priority' => $promotion->getPriority(),
            'exclusive' => $promotion->isExclusive(),
            'usage_limit' => $promotion->getUsageLimit(),
            'used' => $promotion->getUsed(),
            'coupon_based' => $promotion->isCouponBased(),
            'starts_at' => $this->iso($promotion->getStartsAt()),
            'ends_at' => $this->iso($promotion->getEndsAt()),
            'date_created' => $this->iso($promotion->getCreatedAt()),
            'date_updated' => $this->iso($promotion->getUpdatedAt()),
            'codes' => $codes,
            'actions' => $actions,
        ];
    }

    public function serializeRefund(OrderInterface $order): array
    {
        return [
            'id' => $order->getId(),
            'order_id' => $order->getId(),
            'order_number' => $order->getNumber(),
            'amount' => $this->money($order->getTotal()),
            'currency' => (string) $order->getCurrencyCode(),
            'reason' => null,
            'date_created' => $this->iso($order->getUpdatedAt()),
        ];
    }

    private function address(?AddressInterface $address): ?array
    {
        if (null === $address) {
            return null;
        }

        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'company' => $address->getCompany(),
            'address_1' => $address->getStreet(),
            'address_2' => null,
            'city' => $address->getCity(),
            'state' => $address->getProvinceName() ?: $address->getProvinceCode(),
            'postcode' => $address->getPostcode(),
            'country' => $address->getCountryCode(),
            'phone' => $address->getPhoneNumber(),
        ];
    }

    private function lineItems(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }
            $variant = $item->getVariant();
            $product = $variant?->getProduct();
            $items[] = [
                'id' => $item->getId(),
                'name' => (string) $item->getProductName(),
                'product_id' => $product?->getId(),
                'variation_id' => $variant?->getId(),
                'variant_code' => $variant?->getCode(),
                'sku' => $variant?->getCode(),
                'quantity' => $item->getQuantity(),
                'price' => $this->money($item->getUnitPrice()),
                'total' => $this->money($item->getTotal()),
                'subtotal' => $this->money($item->getSubtotal()),
                'tax' => $this->money($item->getTaxTotal()),
                'discount' => $this->money($this->itemDiscountTotal($item)),
            ];
        }

        return $items;
    }

    private function shippingLines(OrderInterface $order): array
    {
        $lines = [];
        foreach ($order->getShipments() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }
            $lines[] = [
                'id' => $shipment->getId(),
                'method_title' => $shipment->getMethod()?->getName(),
                'method_code' => $shipment->getMethod()?->getCode(),
                'total' => $this->money($order->getShippingTotal()),
                'tracking_number' => $shipment->getTracking(),
            ];
        }

        return $lines;
    }

    private function couponLines(OrderInterface $order): array
    {
        $lines = [];
        $promotionCoupon = $order->getPromotionCoupon();
        if (null !== $promotionCoupon) {
            $lines[] = [
                'code' => $promotionCoupon->getCode(),
                'discount' => $this->money($this->orderDiscountTotal($order)),
            ];
        }

        return $lines;
    }

    private function taxLines(OrderInterface $order): array
    {
        $tax = $order->getTaxTotal();
        if (0 === $tax) {
            return [];
        }

        return [['total' => $this->money($tax)]];
    }

    private function payments(OrderInterface $order): array
    {
        $payments = [];
        foreach ($order->getPayments() as $payment) {
            if (!$payment instanceof PaymentInterface) {
                continue;
            }
            $payments[] = [
                'method' => $payment->getMethod()?->getCode(),
                'method_title' => $payment->getMethod()?->getName(),
                'state' => (string) $payment->getState(),
                'amount' => $this->money($payment->getAmount()),
                'currency' => (string) $payment->getCurrencyCode(),
                'date' => $this->iso($payment->getUpdatedAt()),
            ];
        }

        return $payments;
    }

    private function variant(ProductVariantInterface $variant): array
    {
        $channelPricing = null;
        foreach ($variant->getChannelPricings() as $pricing) {
            $channelPricing = $pricing;
            break;
        }

        return [
            'id' => $variant->getId(),
            'code' => $variant->getCode(),
            'name' => $variant->getName(),
            'price' => null !== $channelPricing ? $this->money($channelPricing->getPrice()) : null,
            'original_price' => null !== $channelPricing && null !== $channelPricing->getOriginalPrice()
                ? $this->money($channelPricing->getOriginalPrice())
                : null,
            'on_sale' => null !== $channelPricing && null !== $channelPricing->getOriginalPrice(),
            'on_hand' => $variant->getOnHand(),
            'tracked' => $variant->isTracked(),
        ];
    }

    private function orderDiscountTotal(OrderInterface $order): int
    {
        $total = 0;
        foreach ($order->getAdjustments() as $adjustment) {
            if (!$adjustment instanceof AdjustmentInterface) {
                continue;
            }
            if (AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT === $adjustment->getType()) {
                $total += $adjustment->getAmount();
            }
        }
        foreach ($order->getItems() as $item) {
            $total += $this->itemDiscountTotal($item);
        }

        return $total;
    }

    private function itemDiscountTotal(OrderItemInterface $item): int
    {
        $total = 0;
        foreach ($item->getAdjustments() as $adjustment) {
            if (!$adjustment instanceof AdjustmentInterface) {
                continue;
            }
            if (\in_array($adjustment->getType(), [
                AdjustmentInterface::ORDER_ITEM_PROMOTION_ADJUSTMENT,
                AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT,
            ], true)) {
                $total += $adjustment->getAmount();
            }
        }

        return $total;
    }

    private function paymentMethod(OrderInterface $order): ?string
    {
        $payment = $order->getLastPayment();

        return $payment?->getMethod()?->getCode();
    }

    private function paymentMethodTitle(OrderInterface $order): ?string
    {
        $payment = $order->getLastPayment();

        return $payment?->getMethod()?->getName();
    }

    private function money(?int $cents): string
    {
        $value = (null === $cents) ? 0 : $cents;

        return number_format($value / 100, 2, '.', '');
    }

    private function iso(?\DateTimeInterface $date): ?string
    {
        if (null === $date) {
            return null;
        }
        $utc = (new \DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s\Z');
    }
}
