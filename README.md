# Fullmetrix Sylius Plugin

[![Latest Version](https://img.shields.io/packagist/v/fullmetrix/sylius-plugin.svg)](https://packagist.org/packages/fullmetrix/sylius-plugin)

Connects a Sylius store to Fullmetrix. Streams orders, customers, products, categories and promotions, dispatches realtime entity webhooks and visitor tracking events, and supports remote coupon management.

Compatible with Sylius 1.13 LTS and Sylius 2.0 (PHP 8.1+, Symfony 6.4 or 7.x).

## Installation

```bash
composer require fullmetrix/sylius-plugin
```

Register the bundle in `config/bundles.php`:

```php
return [
    Fullmetrix\SyliusPlugin\FullmetrixPlugin::class => ['all' => true],
];
```

Import the plugin routes in `config/routes.yaml`:

```yaml
fullmetrix:
    resource: '@FullmetrixPlugin/Resources/config/routing.yaml'
```

Run the migrations to create the plugin tables:

```bash
bin/console doctrine:schema:update --force
```

Clear the cache:

```bash
bin/console cache:clear
```

## Configuration

The plugin works without configuration. Defaults point to `https://fullmetrix.com`. To override, add to `config/packages/fullmetrix.yaml`:

```yaml
fullmetrix:
    api_base: 'https://fullmetrix.com/api/plugin'
    webhook_endpoint: 'https://fullmetrix.com/api/webhooks/ecommerce'
    events_endpoint: 'https://fullmetrix.com/api/webhooks/events'
    tracker_origin: 'https://fullmetrix.com'
```

## Usage

1. In the admin panel, go to `Marketing -> Fullmetrix`.
2. Enter the connection code provided by Fullmetrix (format `FMTX-XXXX-XXXX-XXXX`).
3. Click Connect. The plugin registers with Fullmetrix and receives an HMAC secret.
4. Fullmetrix performs an initial historical sync and then receives realtime webhooks.

## Exposed endpoints

All endpoints require an HMAC signature in the headers (`X-Fullmetrix-Signature`, `X-Fullmetrix-Timestamp`, `X-Fullmetrix-Connection-Code`).

- `GET /fullmetrix/api/export?type=orders|customers|products|categories|coupons|settings`
- `GET /fullmetrix/api/stream` (NDJSON stream of every entity)
- `GET /fullmetrix/api/stream/{entity}`
- `GET /fullmetrix/api/counts`
- `GET /fullmetrix/api/updated?type=orders&days=30`
- `POST /fullmetrix/api/command` (coupon.create, coupon.update, coupon.delete)

## Architecture

- `ConfigStore` and `Logger` persist config keys and dedup-logged entries in dedicated tables.
- `HmacSigner` signs every outbound request and verifies every inbound request, with a 300s tolerance.
- `WebhookQueue` buffers entity changes during the request and flushes them on `kernel.terminate`, fire-and-forget.
- `TrackingQueue` buffers visitor events and flushes them on `kernel.terminate`, reading `fm_vid/fm_sid/fm_cid` cookies.
- `EntityWebhookSubscriber` listens to Doctrine `postPersist`/`postUpdate`; `OrderStateMachineSubscriber` adds Sylius state machine transitions.
- `TrackerInjectionSubscriber` injects the Fullmetrix JS tracker into storefront responses.
- `CartRecoveryController` rebuilds an abandoned cart from a signed URL.

## License

Proprietary. See LICENSE.
