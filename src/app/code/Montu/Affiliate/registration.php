<?php
/**
 * Montu Affiliate Tracking Extension
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Montu_Affiliate',
    __DIR__
);

