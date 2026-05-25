<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class FullmetrixPlugin extends Bundle
{
    public const VERSION = '1.0.0';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
