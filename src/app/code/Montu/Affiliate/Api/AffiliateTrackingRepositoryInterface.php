<?php
/**
 * Montu Affiliate Tracking Extension - Repository Interface
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Api;

interface AffiliateTrackingRepositoryInterface
{
    /**
     * Save affiliate code for a quote
     *
     * @param int $quoteId
     * @param string $affiliateCode
     * @return void
     */
    public function saveByQuoteId(int $quoteId, string $affiliateCode): void;

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
     * Get affiliate code by quote ID
     *
     * @param int $quoteId
     * @return string|null
     */
    public function getByQuoteId(int $quoteId): ?string;

    /**
     * Check if tracking exists for quote
     *
     * @param int $quoteId
     * @return bool
     */
    public function existsForQuote(int $quoteId): bool;

    /**
     * Check if tracking exists for order
     *
     * @param int $orderId
     * @return bool
     */
    public function existsForOrder(int $orderId): bool;
}

