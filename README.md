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

## Support

- support@fullmetrix.com
- https://fullmetrix.com
