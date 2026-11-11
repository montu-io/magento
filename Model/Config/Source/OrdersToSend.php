<?php
/**
 * Montu Affiliate Tracking Extension - Orders To Send Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\Config;

class OrdersToSend implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ORDERS_AFFILIATE_CODE, 'label' => __('Only orders with an affiliate code')],
            [
                'value' => Config::ORDERS_AFFILIATE_CODE_OR_COUPON,
                'label' => __('Orders with an affiliate code or any coupon')
            ],
            [
                'value' => Config::ORDERS_AFFILIATE_CODE_OR_COUPON_PREFIX,
                'label' => __('Orders with an affiliate code or a coupon starting with a configured prefix')
            ],
            ['value' => Config::ORDERS_ALL, 'label' => __('All orders')],
        ];
    }
}
