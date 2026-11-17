<?php
/**
 * Montu Affiliate Tracking Extension - Repository Interface
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Api;

use Montu\Affiliate\Model\TrackingState;

interface AffiliateTrackingRepositoryInterface
{
    /**
     * Save the affiliate code and referral link of the shopper's last click for a
     * quote: inserts the quote's row, or overwrites both when either differs from
     * what the row holds. Only a row not yet linked to an order counts: an order's
     * own row is never touched, a restored quote gets a new one.
     *
     * @param int $quoteId
     * @param string $affiliateCode
     * @param string|null $referralLink Null when the click carried no valid link
     * @return void
     */
    public function saveByQuoteId(int $quoteId, string $affiliateCode, ?string $referralLink): void;

    /**
     * Link existing quote tracking record to an order
     *
     * @param int $quoteId
     * @param int $orderId
     * @return void
     */
    public function linkToOrder(int $quoteId, int $orderId): void;

    /**
     * Get affiliate code by order ID
     *
     * @param int $orderId
     * @return string|null
     */
    public function getByOrderId(int $orderId): ?string;

    /**
     * Get the tracking row of an order (affiliate code, referral link, last snapshot)
     *
     * @param int $orderId
     * @return TrackingState|null
     */
    public function findByOrderId(int $orderId): ?TrackingState;

}

