<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;

final class StoreSettingsProvider
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $channel = null;
        try {
            $channel = $this->channelContext->getChannel();
        } catch (\Throwable) {
            $channel = null;
        }

        $currency = 'EUR';
        $timezone = date_default_timezone_get() ?: 'UTC';
        $currencyPosition = 'right';
        $thousandSeparator = ',';
        $decimalSeparator = '.';
        $numDecimals = 2;

        if ($channel instanceof ChannelInterface) {
            $base = $channel->getBaseCurrency();
            if (null !== $base && null !== $base->getCode()) {
                $currency = $base->getCode();
            }
        }

        $locale = 'en_US';
        try {
            $locale = $this->localeContext->getLocaleCode();
        } catch (\Throwable) {
        }

        return [
            'currency' => $currency,
            'timezone' => $timezone,
            'locale' => $locale,
            'currencyPosition' => $currencyPosition,
            'thousandSeparator' => $thousandSeparator,
            'decimalSeparator' => $decimalSeparator,
            'numDecimals' => $numDecimals,
        ];
    }
}
