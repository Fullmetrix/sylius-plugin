<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Symfony\Contracts\Service\ResetInterface;

final class DeliveryBreaker implements ResetInterface
{
    public const REASON_PAUSED = 'paused';

    public const REASON_UNREACHABLE = 'unreachable';

    public const REASON_TIME_BUDGET = 'time_budget';

    private const PAUSE_SECONDS = 30;

    private const FAILURES_BEFORE_PAUSE = 3;

    private const BUDGET_MS = 5000;

    private const MIN_CALL_MS = 100;

    private bool $tripped = false;

    private ?float $deadline = null;

    public function __construct(private readonly ConfigStore $config)
    {
    }

    public function startBudget(): void
    {
        $this->deadline = microtime(true) + self::BUDGET_MS / 1000;
    }

    public function remainingMs(): ?int
    {
        if (null === $this->deadline) {
            return null;
        }

        return max(0, (int) (($this->deadline - microtime(true)) * 1000));
    }

    public function allows(): bool
    {
        return null === $this->blockReason();
    }

    public function blockReason(bool $essential = false): ?string
    {
        if ($this->tripped) {
            return self::REASON_UNREACHABLE;
        }
        if ($essential) {
            return null;
        }

        $remaining = $this->remainingMs();
        if (null !== $remaining && $remaining < self::MIN_CALL_MS) {
            return self::REASON_TIME_BUDGET;
        }

        if ((int) $this->config->get(ConfigStore::KEY_DELIVERY_PAUSED_UNTIL, 0) > time()) {
            return self::REASON_PAUSED;
        }

        return null;
    }

    /** @param array{status: int, unreachable?: bool} $response */
    public function record(array $response): bool
    {
        if (!($response['unreachable'] ?? false)) {
            if ($response['status'] > 0 && (int) $this->config->get(ConfigStore::KEY_DELIVERY_FAILURES, 0) > 0) {
                $this->config->delete(ConfigStore::KEY_DELIVERY_FAILURES);
            }

            return true;
        }

        if ($this->tripped) {
            return false;
        }
        $this->tripped = true;

        $failures = (int) $this->config->get(ConfigStore::KEY_DELIVERY_FAILURES, 0) + 1;
        if ($failures >= self::FAILURES_BEFORE_PAUSE) {
            $this->config->set(ConfigStore::KEY_DELIVERY_PAUSED_UNTIL, time() + self::PAUSE_SECONDS);
            $this->config->delete(ConfigStore::KEY_DELIVERY_FAILURES);
        } else {
            $this->config->set(ConfigStore::KEY_DELIVERY_FAILURES, $failures);
        }

        return false;
    }

    public function reset(): void
    {
        $this->tripped = false;
        $this->deadline = null;
    }
}
