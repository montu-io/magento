<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Fallback Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\Config;

class StorefrontFallback implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::FALLBACK_EMPTY, 'label' => __('Show an empty page')],
            ['value' => Config::FALLBACK_REDIRECT_HOME, 'label' => __('Redirect to the store home page')],
        ];
    }
}
