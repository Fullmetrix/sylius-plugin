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

Add this line to the crontab of the user that runs the store:

```bash
*/10 * * * * cd /path/to/store && bin/console fullmetrix:tracker:refresh --env=prod > /dev/null 2>&1
```

The command downloads the verified Fullmetrix script into `public/media/fullmetrix/` and the store serves it from its own domain. Without this line, or if the folder is not writable, the script keeps loading from Fullmetrix.

Before uninstalling the plugin, run `bin/console fullmetrix:tracker:refresh --remove` and delete the cron line.

## Support

- support@fullmetrix.com
- https://fullmetrix.com
