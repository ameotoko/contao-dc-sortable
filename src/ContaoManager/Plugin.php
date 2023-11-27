<?php

declare(strict_types=1);

/**
 * @author Andrey Vinichenko <andrey.vinichenko@gmail.com>
 */

namespace Ameotoko\DCSortableBundle\ContaoManager;

use Ameotoko\DCSortableBundle\AmeotokoDCSortableBundle;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(AmeotokoDCSortableBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }
}
