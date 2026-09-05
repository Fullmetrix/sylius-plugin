<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\EventSubscriber;

use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\PluginConfigProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CheckoutConsentInjectionSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ANCHOR = '/<input\b[^>]*name="sylius_checkout_complete\[_token\]"[^>]*>/i';

    private const NOTES_ANCHOR = '/<textarea\b[^>]*name="sylius_checkout_complete\[notes\]"[^>]*>/i';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly PluginConfigProvider $pluginConfig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->config->isActive()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ('sylius_shop_checkout_complete' !== $route && !str_contains($request->getPathInfo(), '/checkout/complete')) {
            return;
        }

        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        $consent = $this->pluginConfig->getCheckoutConsent();
        if (null === $consent) {
            return;
        }

        $html = $this->buildHtml($consent);
        $callback = static fn (array $matches): string => $html . $matches[0];

        $count = 0;
        $injected = preg_replace_callback(self::TOKEN_ANCHOR, $callback, $content, 1, $count);
        if (0 === $count || !\is_string($injected)) {
            $injected = preg_replace_callback(self::NOTES_ANCHOR, $callback, $content, 1, $count);
        }
        if (0 === $count || !\is_string($injected)) {
            return;
        }

        $response->setContent($injected);
    }

    private function buildHtml(array $consent): string
    {
        $label = htmlspecialchars((string) $consent['label'], \ENT_QUOTES, 'UTF-8');
        $checked = !empty($consent['defaultChecked']) ? ' checked' : '';
        $textColor = $this->sanitizeColor($consent['textColor'] ?? null);
        $accentColor = $this->sanitizeColor($consent['accentColor'] ?? null);

        $labelStyle = 'display:flex;align-items:flex-start;gap:8px;cursor:pointer;line-height:1.4;';
        if (null !== $textColor) {
            $labelStyle .= 'color:' . $textColor . ';';
        }
        $boxStyle = 'margin-top:3px;width:16px;height:16px;flex-shrink:0;';
        if (null !== $accentColor) {
            $boxStyle .= 'accent-color:' . $accentColor . ';';
        }

        return sprintf(
            '<div class="fullmetrix-checkout-consent" style="margin:16px 0;">'
            . '<label style="%s">'
            . '<input type="hidden" name="_fullmetrix_consent" value="0">'
            . '<input type="checkbox" name="_fullmetrix_consent" value="1"%s style="%s">'
            . '<span>%s</span>'
            . '</label>'
            . '</div>',
            htmlspecialchars($labelStyle, \ENT_QUOTES, 'UTF-8'),
            $checked,
            htmlspecialchars($boxStyle, \ENT_QUOTES, 'UTF-8'),
            $label,
        );
    }

    private function sanitizeColor(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        if (1 === preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
            return $value;
        }
        if (1 === preg_match('/^[a-zA-Z]{3,20}$/', $value)) {
            return $value;
        }

        return null;
    }
}
