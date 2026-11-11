<?php
/**
 * Montu Affiliate Tracking Extension - Order Fingerprint
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Sales\Model\Order;

/**
 * Hash of the order facts whose change produces an order.updated event:
 * state, status and the paid / refunded / canceled totals.
 */
class OrderFingerprint
{
    /**
     * @param Order $order
     * @return string
     */
    public function forOrder(Order $order): string
    {
        return hash('sha256', implode('|', [
            (string) $order->getState(),
            (string) $order->getStatus(),
            $this->amount($order->getTotalPaid()),
            $this->amount($order->getTotalRefunded()),
            $this->amount($order->getTotalCanceled()),
        ]));
    }

    /**
     * Normalise a money value so "10", "10.0000" and 10.0 hash the same
     *
     * @param mixed $value
     * @return string
     */
    private function amount($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
