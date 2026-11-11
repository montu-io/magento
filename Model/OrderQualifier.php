<?php
/**
 * Montu Affiliate Tracking Extension - Order Qualifier
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Sales\Model\Order;

/**
 * Decides whether a new order is reported to Montu (order.placed)
 */
class OrderQualifier
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Check the order against the "Orders to send" mode and the excluded customer groups
     *
     * @param Order $order
     * @param string $affiliateCode Empty when the order has none
     * @return bool
     */
    public function qualifies(Order $order, string $affiliateCode): bool
    {
        $storeId = (int) $order->getStoreId();

        if (in_array((int) $order->getCustomerGroupId(), $this->config->getExcludedCustomerGroups($storeId), true)) {
            return false;
        }

        $coupon = trim((string) $order->getCouponCode());

        return match ($this->config->getOrdersToSendMode($storeId)) {
            Config::ORDERS_AFFILIATE_CODE => $affiliateCode !== '',
            Config::ORDERS_ALL => true,
            Config::ORDERS_AFFILIATE_CODE_OR_COUPON_PREFIX => $affiliateCode !== ''
                || $this->hasPrefix($coupon, $this->config->getCouponPrefixes($storeId)),
            default => $affiliateCode !== '' || $coupon !== '',
        };
    }

    /**
     * Case-insensitive "starts with any" check
     *
     * @param string $coupon
     * @param string[] $prefixes Lower-cased
     * @return bool
     */
    private function hasPrefix(string $coupon, array $prefixes): bool
    {
        $coupon = strtolower($coupon);
        foreach ($prefixes as $prefix) {
            if ($coupon !== '' && str_starts_with($coupon, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
