<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EntitySerializer
{
    private const META_VALUE_MAX_LENGTH = 20000;

    private const META_TOTAL_MAX_LENGTH = 65536;

    private const META_SHORT_VALUE_LENGTH = 512;

    private const META_KEY_MAX_LENGTH = 80;

    private const SENSITIVE_FIELDS = [
        'password', 'plainPassword', 'salt', 'passwordResetToken',
        'emailVerificationToken', 'passwordRequestedAt', 'token',
    ];

    /** Motifs bilingues, pour les champs ajoutes par un plugin ou un projet. */
    private const SENSITIVE_FIELD_PATTERNS = [
        'password', 'passwd', 'secret', 'token', 'apikey', 'apiKey',
        'privatekey', 'salt', 'nonce', 'credential',
        'jeton', 'motdepasse', 'clesecrete', 'cleprivee', 'empreinte', 'signature',
    ];

    private const ORDER_MAPPED_FIELDS = [
        'id', 'number', 'state', 'checkoutState', 'paymentState', 'shippingState',
        'currencyCode', 'total', 'itemsTotal', 'createdAt', 'updatedAt', 'notes',
    ];

    private const CUSTOMER_MAPPED_FIELDS = [
        'id', 'email', 'emailCanonical', 'firstName', 'lastName',
        'phoneNumber', 'gender', 'birthday', 'createdAt', 'updatedAt',
    ];

    private const PRODUCT_MAPPED_FIELDS = ['id', 'code', 'createdAt', 'updatedAt'];

    private const LINE_ITEM_MAPPED_FIELDS = [
        'id', 'quantity', 'unitPrice', 'total', 'subtotal', 'productName', 'variantName',
    ];

    private const ADDRESS_MAPPED_FIELDS = [
        'id', 'firstName', 'lastName', 'company', 'street', 'city',
        'postcode', 'countryCode', 'provinceCode', 'provinceName', 'phoneNumber',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductImageUrl $imageUrl,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

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
            'customer_email' => $this->orderEmail($order),
            'customer_note' => $order->getNotes(),
            'payment_method' => $this->paymentMethod($order),
            'payment_method_title' => $this->paymentMethodTitle($order),
            'billing' => $this->address($order->getBillingAddress(), $this->orderEmail($order)),
            'shipping' => $this->address($order->getShippingAddress(), $this->orderEmail($order)),
            'line_items' => $this->lineItems($order),
            'shipping_lines' => $this->shippingLines($order),
            'coupon_lines' => $this->couponLines($order),
            'fee_lines' => [],
            'tax_lines' => $this->taxLines($order),
            'payments' => $this->payments($order),
            'meta_data' => array_merge(
                $this->extraFieldsMeta($order, self::ORDER_MAPPED_FIELDS),
                $this->extraFieldsMeta($order->getBillingAddress(), self::ADDRESS_MAPPED_FIELDS, 'billing_'),
                $this->extraFieldsMeta($order->getShippingAddress(), self::ADDRESS_MAPPED_FIELDS, 'shipping_'),
            ),
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
            'meta_data' => array_merge(
                $this->extraFieldsMeta($customer, self::CUSTOMER_MAPPED_FIELDS),
                $this->extraFieldsMeta($customer->getDefaultAddress(), self::ADDRESS_MAPPED_FIELDS, 'billing_'),
            ),
        ];
    }

    /**
     * Le produit puis une ligne par variante, au format des autres connecteurs :
     * id compose `<produit>_<variante>`, `parent_id` et `type: variation`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serializeProductRows(ProductInterface $product): array
    {
        $rows = [$this->serializeProduct($product)];
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $rows[] = $this->serializeVariant($product, $variant);
            }
        }

        return $rows;
    }

    public function serializeProduct(ProductInterface $product): array
    {
        $variants = [];
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $variants[] = $variant;
            }
        }

        $categories = [];
        foreach ($product->getTaxons() as $taxon) {
            if (null !== $taxon->getId()) {
                $categories[] = ['id' => $taxon->getId(), 'name' => (string) $taxon->getName()];
            }
        }

        $stock = 0;
        $tracked = false;
        foreach ($variants as $variant) {
            $stock += max(0, (int) $variant->getOnHand() - (int) $variant->getOnHold());
            $tracked = $tracked || $variant->isTracked();
        }
        $pricing = [] === $variants ? [] : $this->pricing($variants[0]);
        $imageUrl = $this->imageUrl->forProduct($product);

        return array_merge([
            'id' => $product->getId(),
            'type' => \count($variants) > 1 ? 'variable' : 'simple',
            'parent_id' => null,
            'name' => (string) $product->getName(),
            'code' => $product->getCode(),
            'sku' => $product->getCode(),
            'description' => $product->getDescription(),
            'short_description' => $product->getShortDescription(),
            'slug' => $product->getSlug(),
            'permalink' => $this->productUrl($product),
            'status' => $product->isEnabled() ? 'publish' : 'draft',
            'featured' => false,
            'categories' => $categories,
            'category_ids' => array_column($categories, 'id'),
            'image_url' => $imageUrl,
            'images' => null === $imageUrl ? [] : [['src' => $imageUrl]],
            'stock_quantity' => $stock,
            'stock_status' => !$tracked || $stock > 0 ? 'instock' : 'outofstock',
            'manage_stock' => $tracked,
            'date_created' => $this->iso($product->getCreatedAt()),
            'date_modified' => $this->iso($product->getUpdatedAt()),
            'meta_data' => $this->extraFieldsMeta($product, self::PRODUCT_MAPPED_FIELDS),
        ], $pricing);
    }

    private function serializeVariant(ProductInterface $product, ProductVariantInterface $variant): array
    {
        $attributes = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $attributes[] = [
                'name' => (string) ($optionValue->getOption()?->getName() ?? $optionValue->getOptionCode()),
                'option' => (string) $optionValue->getValue(),
            ];
        }
        $stock = max(0, (int) $variant->getOnHand() - (int) $variant->getOnHold());
        $imageUrl = null;
        foreach ($variant->getImages() as $image) {
            $imageUrl = $this->imageUrl->fromPath($image->getPath());

            break;
        }
        $imageUrl ??= $this->imageUrl->forProduct($product);
        $variantName = trim((string) $variant->getName());

        return array_merge([
            'id' => $this->variantExternalId($variant),
            'type' => 'variation',
            'parent_id' => $product->getId(),
            'name' => '' === $variantName ? (string) $product->getName() : $product->getName() . ' - ' . $variantName,
            'code' => $variant->getCode(),
            'sku' => $variant->getCode(),
            'slug' => $product->getSlug(),
            'permalink' => $this->productUrl($product),
            'status' => $variant->isEnabled() && $product->isEnabled() ? 'publish' : 'draft',
            'categories' => [],
            'category_ids' => [],
            'attributes' => $attributes,
            'image_url' => $imageUrl,
            'images' => null === $imageUrl ? [] : [['src' => $imageUrl]],
            'stock_quantity' => $stock,
            'stock_status' => !$variant->isTracked() || $stock > 0 ? 'instock' : 'outofstock',
            'manage_stock' => $variant->isTracked(),
            'weight' => $variant->getWeight(),
            'date_created' => $this->iso($variant->getCreatedAt()),
            'date_modified' => $this->iso($variant->getUpdatedAt()),
            'meta_data' => [],
        ], $this->pricing($variant));
    }

    public function variantExternalId(ProductVariantInterface $variant): string
    {
        return $variant->getProduct()?->getId() . '_' . $variant->getId();
    }

    /** @return array<string, mixed> */
    private function pricing(ProductVariantInterface $variant): array
    {
        $channelPricing = null;
        foreach ($variant->getChannelPricings() as $pricing) {
            $channelPricing = $pricing;

            break;
        }
        if (null === $channelPricing) {
            return ['price' => null, 'regular_price' => null, 'sale_price' => null, 'on_sale' => false];
        }

        $price = $this->money($channelPricing->getPrice());
        $original = $channelPricing->getOriginalPrice();
        $onSale = null !== $original && $original > $channelPricing->getPrice();

        return [
            'price' => $price,
            'regular_price' => $onSale ? $this->money($original) : $price,
            'sale_price' => $onSale ? $price : null,
            'on_sale' => $onSale,
        ];
    }

    private function productUrl(ProductInterface $product): ?string
    {
        $slug = $product->getSlug();
        if (null === $slug || '' === $slug) {
            return null;
        }

        try {
            return $this->urls->generate(
                'sylius_shop_product_show',
                ['slug' => $slug, '_locale' => $product->getTranslation()->getLocale()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Throwable) {
            return null;
        }
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
            'meta_data' => $this->extraFieldsMeta($taxon, ['id', 'code', 'position', 'createdAt', 'updatedAt']),
        ];
    }

    public function serializeCoupon(PromotionInterface $promotion): array
    {
        $codes = [];
        foreach ($promotion->getCoupons() as $coupon) {
            $entry = [
                'code' => $coupon->getCode(),
                'usage_limit' => $coupon->getUsageLimit(),
                'used' => $coupon->getUsed(),
                'expires_at' => $this->iso($coupon->getExpiresAt()),
            ];
            // Sylius 1.13 exposes a per customer limit, Sylius 2 dropped it.
            if (method_exists($coupon, 'getPerCustomerUsageLimit')) {
                $entry['per_customer_usage_limit'] = $coupon->getPerCustomerUsageLimit();
            }
            $codes[] = $entry;
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

    private function address(?AddressInterface $address, ?string $email = null): ?array
    {
        if (null === $address) {
            return null;
        }

        return [
            'email' => $email,
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
            $variant = $item->getVariant();
            $product = $variant?->getProduct();
            $items[] = [
                'id' => $item->getId(),
                'name' => (string) $item->getProductName(),
                'product_id' => $product?->getId(),
                'variation_id' => null !== $variant ? $this->variantExternalId($variant) : null,
                'variant_code' => $variant?->getCode(),
                'sku' => $variant?->getCode(),
                'quantity' => $item->getQuantity(),
                'price' => $this->money($item->getUnitPrice()),
                'total' => $this->money($item->getTotal()),
                'subtotal' => $this->money($item->getSubtotal()),
                'tax' => $this->money($item->getTaxTotal()),
                'discount' => $this->money($this->itemDiscountTotal($item)),
                'meta_data' => array_merge(
                    $this->extraFieldsMeta($item, self::LINE_ITEM_MAPPED_FIELDS),
                    $this->extraFieldsMeta($variant, ['id', 'code'], 'variant_'),
                ),
            ];
        }

        return $items;
    }

    private function shippingLines(OrderInterface $order): array
    {
        $lines = [];
        foreach ($order->getShipments() as $shipment) {
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

    /**
     * Every Doctrine field that is not already exposed as a top-level key,
     * read from the entity metadata. Fields added by a project extending a
     * Sylius entity are picked up without touching this plugin.
     *
     * @param list<string> $mappedFields
     *
     * @return list<array{key: string, value: string}>
     */
    private function extraFieldsMeta(?object $entity, array $mappedFields, string $prefix = ''): array
    {
        if (null === $entity) {
            return [];
        }

        try {
            $metadata = $this->em->getClassMetadata($entity::class);
        } catch (\Throwable) {
            return [];
        }

        $short = [];
        $long = [];

        // Identifiants des associations to-one: lus sur le proxy, ce qui
        // n'ouvre aucune requete, et couvre les entites ajoutees par un plugin.
        foreach ($metadata->getAssociationMappings() as $name => $mapping) {
            if (empty($mapping['isOwningSide']) || !($mapping['type'] & ClassMetadata::TO_ONE)) {
                continue;
            }
            if (\in_array($name, $mappedFields, true)) {
                continue;
            }

            try {
                $related = $metadata->getFieldValue($entity, $name);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_object($related) || !method_exists($related, 'getId')) {
                continue;
            }
            $relatedId = $related->getId();
            if (null === $relatedId || \is_object($relatedId)) {
                continue;
            }
            $short[] = [
                'key' => mb_strcut($prefix . $name . '_id', 0, self::META_KEY_MAX_LENGTH, 'UTF-8'),
                'value' => (string) $relatedId,
            ];
        }

        foreach ($metadata->getFieldNames() as $field) {
            if (\in_array($field, $mappedFields, true) ||
                \in_array($field, self::SENSITIVE_FIELDS, true) ||
                self::isSensitiveField($field)) {
                continue;
            }

            try {
                $value = $metadata->getFieldValue($entity, $field);
            } catch (\Throwable) {
                continue;
            }
            $text = $this->stringifyFieldValue($value);
            if (null === $text || '' === $text || 1 !== preg_match('//u', $text)) {
                continue;
            }
            $key = mb_strcut($prefix . $field, 0, self::META_KEY_MAX_LENGTH, 'UTF-8');
            if (\strlen($text) <= self::META_SHORT_VALUE_LENGTH) {
                $short[] = ['key' => $key, 'value' => $text];

                continue;
            }
            $long[] = ['key' => $key, 'value' => mb_strcut($text, 0, self::META_VALUE_MAX_LENGTH, 'UTF-8')];
        }

        $pairs = $short;
        $budget = self::META_TOTAL_MAX_LENGTH;
        foreach ($short as $pair) {
            $budget -= \strlen($pair['value']);
        }
        foreach ($long as $pair) {
            if ($budget <= 0) {
                break;
            }
            $pairs[] = [
                'key' => $pair['key'],
                'value' => \strlen($pair['value']) > $budget
                    ? mb_strcut($pair['value'], 0, $budget, 'UTF-8')
                    : $pair['value'],
            ];
            $budget -= \strlen($pair['value']);
        }

        return $pairs;
    }

    private static function isSensitiveField(string $field): bool
    {
        $lower = strtolower(str_replace('_', '', $field));
        foreach (self::SENSITIVE_FIELD_PATTERNS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function stringifyFieldValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $this->iso($value);
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function money(?int $cents): string
    {
        $value = (null === $cents) ? 0 : $cents;

        return number_format($value / 100, 2, '.', '');
    }

    /**
     * Sylius 1.13 carries the address email on the order, Sylius 2 only on the customer.
     */
    private function orderEmail(OrderInterface $order): ?string
    {
        $email = $order->getCustomer()?->getEmail();
        if (null !== $email && '' !== $email) {
            return $email;
        }

        return method_exists($order, 'getEmail') ? $order->getEmail() : null;
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
