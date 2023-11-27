<?php

declare(strict_types=1);

/**
 * @author Andrey Vinichenko <andrey.vinichenko@gmail.com>
 */

namespace Ameotoko\DCSortableBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class AmeotokoDCSortableBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
