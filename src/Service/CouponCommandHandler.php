<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Promotion\Factory\PromotionCouponFactoryInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Promotion\Model\PromotionCouponInterface;
use Sylius\Component\Promotion\Model\PromotionRuleInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class CouponCommandHandler
{
    public const ACTION_CREATE = 'coupon.create';

    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FactoryInterface $promotionFactory,
        private readonly PromotionCouponFactoryInterface $couponFactory,
        private readonly FactoryInterface $promotionActionFactory,
        private readonly FactoryInterface $promotionRuleFactory,
        private readonly RepositoryInterface $channelRepository,
        private readonly ?RepositoryInterface $promotionRepository = null,
        private readonly ?RepositoryInterface $couponRepository = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function handle(string $action, array $payload): array
    {
        return match ($action) {
            self::ACTION_CREATE => $this->create($payload),
            default => ['success' => false, 'error' => 'unknown_action'],
        };
    }

    private function create(array $payload): array
    {
        $code = $payload['code'] ?? null;
        if (!\is_string($code) || 1 !== preg_match(self::CODE_PATTERN, $code)) {
            return ['success' => false, 'error' => 'invalid_code'];
        }
        $invalid = $this->invalidPayload($payload);
        if (null !== $invalid) {
            return ['success' => false, 'error' => $invalid];
        }
        if ($this->codeTaken($code)) {
            return ['success' => false, 'error' => 'code_already_exists'];
        }

        $channels = [];
        foreach ($this->channelRepository->findBy(['enabled' => true]) as $channel) {
            if ($channel instanceof ChannelInterface && null !== $channel->getCode()) {
                $channels[] = $channel;
            }
        }
        if ([] === $channels) {
            return ['success' => false, 'error' => 'no_channel'];
        }

        /** @var PromotionInterface $promotion */
        $promotion = $this->promotionFactory->createNew();
        $promotion->setCode($code);
        $promotion->setName((string) ($payload['description'] ?? $code));
        $promotion->setDescription((string) ($payload['description'] ?? ''));
        $promotion->setCouponBased(true);
        $promotion->setExclusive((bool) ($payload['exclusive'] ?? false));
        $promotion->setPriority((int) ($payload['priority'] ?? 0));
        foreach ($channels as $channel) {
            $promotion->addChannel($channel);
        }

        if (isset($payload['usageLimit'])) {
            $promotion->setUsageLimit((int) $payload['usageLimit']);
        }
        if (!empty($payload['startsAt'])) {
            $promotion->setStartsAt(new \DateTime((string) $payload['startsAt']));
        }
        if (!empty($payload['expiresAt'])) {
            $promotion->setEndsAt(new \DateTime((string) $payload['expiresAt']));
        }

        $this->attachAction($promotion, $payload, $channels);
        $this->attachRules($promotion, $payload, $channels);

        /** @var PromotionCouponInterface $coupon */
        $coupon = $this->couponFactory->createForPromotion($promotion);
        $coupon->setCode($code);
        if (isset($payload['usageLimit'])) {
            $coupon->setUsageLimit((int) $payload['usageLimit']);
        }
        // Sylius 1.13 exposes a per customer limit, Sylius 2 dropped it.
        if (isset($payload['usageLimitPerUser']) && method_exists($coupon, 'setPerCustomerUsageLimit')) {
            $coupon->setPerCustomerUsageLimit((int) $payload['usageLimitPerUser']);
        }
        if (!empty($payload['expiresAt'])) {
            $coupon->setExpiresAt(new \DateTime((string) $payload['expiresAt']));
        }
        $promotion->addCoupon($coupon);

        $this->em->persist($promotion);
        $this->em->persist($coupon);
        $this->em->flush();

        return ['success' => true, 'data' => ['id' => $promotion->getId(), 'code' => $code]];
    }

    private function codeTaken(string $code): bool
    {
        foreach ([$this->promotionRepository, $this->couponRepository] as $repository) {
            if (null !== $repository && null !== $repository->findOneBy(['code' => $code])) {
                return true;
            }
        }

        return false;
    }

    private function invalidPayload(array $payload): ?string
    {
        foreach (['amount', 'minimumAmount'] as $field) {
            if (!\array_key_exists($field, $payload) || ('minimumAmount' === $field && null === $payload[$field])) {
                continue;
            }
            if (!is_numeric($payload[$field]) || !is_finite((float) $payload[$field]) || (float) $payload[$field] < 0) {
                return 'invalid_' . $field;
            }
        }
        if ('percentage' === ($payload['discountType'] ?? 'percentage') && (float) ($payload['amount'] ?? 0) > 100) {
            return 'invalid_amount';
        }
        foreach (['usageLimit', 'usageLimitPerUser'] as $limit) {
            if (\array_key_exists($limit, $payload) && (!is_numeric($payload[$limit]) || (int) $payload[$limit] < 1)) {
                return 'invalid_' . $limit;
            }
        }
        foreach (['startsAt', 'expiresAt'] as $date) {
            if (!empty($payload[$date]) && (!\is_string($payload[$date]) || false === strtotime($payload[$date]))) {
                return 'invalid_' . $date;
            }
        }
        if (!empty($payload['giftProduct']) || !empty($payload['productIds'])) {
            return 'unsupported_gift_product';
        }

        return null;
    }

    private function attachAction(PromotionInterface $promotion, array $payload, array $channels): void
    {
        $type = (string) ($payload['discountType'] ?? 'percentage');
        $amount = (float) ($payload['amount'] ?? 0);

        /** @var PromotionActionInterface $action */
        $action = $this->promotionActionFactory->createNew();

        if ('percentage' === $type) {
            $action->setType('order_percentage_discount');
            $action->setConfiguration(['percentage' => $amount / 100]);
        } elseif ('free_shipping' === $type) {
            $action->setType('shipping_percentage_discount');
            $action->setConfiguration(['percentage' => 1.0]);
        } else {
            $action->setType('order_fixed_discount');
            $action->setConfiguration($this->perChannel($channels, (int) round($amount * 100)));
        }

        $promotion->addAction($action);
    }

    private function attachRules(PromotionInterface $promotion, array $payload, array $channels): void
    {
        if (!empty($payload['minimumAmount'])) {
            /** @var PromotionRuleInterface $rule */
            $rule = $this->promotionRuleFactory->createNew();
            $rule->setType('item_total');
            $rule->setConfiguration($this->perChannel($channels, (int) round(((float) $payload['minimumAmount']) * 100)));
            $promotion->addRule($rule);
        }
    }

    private function perChannel(array $channels, int $cents): array
    {
        $configuration = [];
        foreach ($channels as $channel) {
            $configuration[(string) $channel->getCode()] = ['amount' => $cents];
        }

        return $configuration;
    }
}
