<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Promotion\Factory\PromotionCouponFactoryInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Promotion\Model\PromotionCouponInterface;
use Sylius\Component\Promotion\Model\PromotionRuleInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class CouponCommandHandler
{
    public const ACTION_CREATE = 'coupon.create';
    public const ACTION_UPDATE = 'coupon.update';
    public const ACTION_DELETE = 'coupon.delete';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FactoryInterface $promotionFactory,
        private readonly PromotionCouponFactoryInterface $couponFactory,
        private readonly FactoryInterface $promotionActionFactory,
        private readonly FactoryInterface $promotionRuleFactory,
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
            self::ACTION_UPDATE => $this->update($payload),
            self::ACTION_DELETE => $this->delete($payload),
            default => ['success' => false, 'error' => 'unknown_action'],
        };
    }

    private function create(array $payload): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ('' === $code) {
            return ['success' => false, 'error' => 'missing_code'];
        }

        /** @var PromotionInterface $promotion */
        $promotion = $this->promotionFactory->createNew();
        $promotion->setCode($code);
        $promotion->setName((string) ($payload['description'] ?? $code));
        $promotion->setDescription((string) ($payload['description'] ?? ''));
        $promotion->setCouponBased(true);
        $promotion->setExclusive((bool) ($payload['exclusive'] ?? false));
        $promotion->setPriority((int) ($payload['priority'] ?? 0));

        if (isset($payload['usageLimit']) && null !== $payload['usageLimit']) {
            $promotion->setUsageLimit((int) $payload['usageLimit']);
        }
        if (!empty($payload['startsAt'])) {
            $promotion->setStartsAt(new \DateTime((string) $payload['startsAt']));
        }
        if (!empty($payload['expiresAt'])) {
            $promotion->setEndsAt(new \DateTime((string) $payload['expiresAt']));
        }

        $this->attachAction($promotion, $payload);
        $this->attachRules($promotion, $payload);

        /** @var PromotionCouponInterface $coupon */
        $coupon = $this->couponFactory->createForPromotion($promotion);
        $coupon->setCode($code);
        if (isset($payload['usageLimit']) && null !== $payload['usageLimit']) {
            $coupon->setUsageLimit((int) $payload['usageLimit']);
        }
        if (isset($payload['usageLimitPerUser']) && null !== $payload['usageLimitPerUser']) {
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

    private function update(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (null === $id) {
            return ['success' => false, 'error' => 'missing_id'];
        }

        $promotion = $this->em->getRepository(PromotionInterface::class)->find($id);
        if (!$promotion instanceof PromotionInterface) {
            return ['success' => false, 'error' => 'not_found'];
        }

        if (isset($payload['description'])) {
            $promotion->setDescription((string) $payload['description']);
            $promotion->setName((string) $payload['description']);
        }
        if (isset($payload['usageLimit'])) {
            $promotion->setUsageLimit(null === $payload['usageLimit'] ? null : (int) $payload['usageLimit']);
        }
        if (isset($payload['expiresAt'])) {
            $promotion->setEndsAt(empty($payload['expiresAt']) ? null : new \DateTime((string) $payload['expiresAt']));
        }

        $this->em->flush();

        return ['success' => true, 'data' => ['id' => $promotion->getId(), 'code' => $promotion->getCode()]];
    }

    private function delete(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (null === $id) {
            return ['success' => false, 'error' => 'missing_id'];
        }

        $promotion = $this->em->getRepository(PromotionInterface::class)->find($id);
        if (!$promotion instanceof PromotionInterface) {
            return ['success' => false, 'error' => 'not_found'];
        }

        $this->em->remove($promotion);
        $this->em->flush();

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function attachAction(PromotionInterface $promotion, array $payload): void
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
            $cents = (int) round($amount * 100);
            $action->setConfiguration(['DEFAULT' => ['amount' => $cents]]);
        }

        $promotion->addAction($action);
    }

    private function attachRules(PromotionInterface $promotion, array $payload): void
    {
        if (!empty($payload['minimumAmount'])) {
            /** @var PromotionRuleInterface $rule */
            $rule = $this->promotionRuleFactory->createNew();
            $rule->setType('item_total');
            $rule->setConfiguration([
                'DEFAULT' => ['amount' => (int) round(((float) $payload['minimumAmount']) * 100)],
            ]);
            $promotion->addRule($rule);
        }
    }
}
