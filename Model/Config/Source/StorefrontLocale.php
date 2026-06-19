<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Locale Source
 *
 * The languages the affiliate storefront can be rendered in. Limited to the
 * locales the module ships translations for (English and Spanish).
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class StorefrontLocale implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'en_US', 'label' => __('English')],
            ['value' => 'es_ES', 'label' => __('Spanish')],
        ];
    }
}
