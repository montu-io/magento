<?php
/**
 * Montu Affiliate Tracking Extension - Customer Data Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\Config;

class CustomerData implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::CUSTOMER_DATA_HASHED,
                'label' => __('Hashed - no personal data, only keyed hashes of email and phone')
            ],
            [
                'value' => Config::CUSTOMER_DATA_NONE,
                'label' => __('None - no personal data and no hashes')
            ],
            [
                'value' => Config::CUSTOMER_DATA_FULL,
                'label' => __('Full - name, email and full addresses (plus the hashes)')
            ],
        ];
    }
}
