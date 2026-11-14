# Fullmetrix for Sylius

Official Fullmetrix plugin for Sylius. It connects your store to [Fullmetrix](https://fullmetrix.com), the analytics, segmentation and marketing platform for ecommerce.

Compatible with Sylius 1.13 and 2.x, PHP 8.1 or later.

## Installation

```bash
composer require fullmetrix/sylius-plugin
```

Register the bundle in `config/bundles.php`:

```php
Fullmetrix\SyliusPlugin\FullmetrixPlugin::class => ['all' => true],
```

Import the routes in `config/routes.yaml`:

```yaml
fullmetrix:
    resource: '@FullmetrixPlugin/Resources/config/routing.yaml'
```

Create the plugin tables and clear the cache:

```bash
bin/console doctrine:migrations:migrate
bin/console cache:clear
```

## Connection

In the admin, go to Marketing, then Fullmetrix, and enter the connection code shown in Fullmetrix.

## Tracking script served by the store

The store serves the tracking script from its own domain only when the refresh command runs from cron. Add this line to the crontab of the user that runs the store:

```bash
*/10 * * * * cd /path/to/store && bin/console fullmetrix:tracker:refresh --env=prod > /dev/null 2>&1
```

The command downloads the verified Fullmetrix script into `public/media/kvo/` and the store serves it from its own domain. Without this line, or if the folder is not writable, the script loads from `https://fullmetrix.com/assets/js/s.js`.

Before uninstalling the plugin, run `bin/console fullmetrix:tracker:refresh --remove` and delete the cron line.

## Service and cookies

The measurement script sends page views, cart and order events to `https://fullmetrix.com` (or to a subdomain of your own store domain when you configured one in Fullmetrix) and sets first-party cookies on the store domain: `_kvo_v` (anonymous visitor id, up to 390 days), `_kvo_s` (session, 30 minutes) and `_kvo_c` (contact identifiers after a login or an order). Stores connected before version 1.8.0 keep the older `fm_vid`, `fm_sid` and `fm_cid` cookies until they expire. The store sets `_kvo_v` again on a same-origin `POST /kvo/k` so browsers keep it longer; no session is started by that request. Tracking consent is not handled by the plugin: call `fm('consent', {...})` from your tag manager or consent tool. Privacy policy: https://fullmetrix.com/privacy

## Support

- support@fullmetrix.com
- https://fullmetrix.com
