<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProductImageUrl
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function forProduct(?ProductInterface $product): ?string
    {
        if (null === $product) {
            return null;
        }

        foreach ($product->getImages() as $image) {
            return $this->fromPath($image->getPath());
        }

        return null;
    }

    public function fromPath(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $context = $this->urls->getContext();
        $host = $context->getHost();
        if ('' === $host || 'localhost' === $host) {
            return null;
        }

        return sprintf('%s://%s/media/image/%s', $context->getScheme(), $host, ltrim($path, '/'));
    }
}
