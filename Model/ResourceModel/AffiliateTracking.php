<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Tracking Resource Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Montu\Affiliate\Model\TrackingState;

class AffiliateTracking extends AbstractDb
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_tracking_resource';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('montu_affiliate_tracking', 'entity_id');
    }

    /**
     * The tracking row linked to an order
     *
     * @param int $orderId
     * @return TrackingState|null
     */
    public function findByOrderId(int $orderId): ?TrackingState
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getMainTable())->where('order_id = ?', $orderId)->limit(1)
        );

        return is_array($row) ? new TrackingState(
            $row['affiliate_code'] !== null ? (string) $row['affiliate_code'] : null,
            $row['referral_link'] !== null ? (string) $row['referral_link'] : null,
            $row['fingerprint'] !== null ? (string) $row['fingerprint'] : null,
            $row['last_state'] !== null ? (string) $row['last_state'] : null,
            $row['last_status'] !== null ? (string) $row['last_status'] : null
        ) : null;
    }

    /**
     * The affiliate code and referral link captured on a quote and not yet linked
     * to an order (written by SaveAffiliateToQuote); an order's own row is read
     * through findByOrderId()
     *
     * @param int $quoteId
     * @return TrackingState|null Null when the quote has no code
     */
    public function findByQuoteId(int $quoteId): ?TrackingState
    {
        if ($quoteId <= 0) {
            return null;
        }

        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable(), ['affiliate_code', 'referral_link'])
                ->where('quote_id = ?', $quoteId)
                ->where('order_id IS NULL')
                ->where('affiliate_code IS NOT NULL')
                ->where("affiliate_code <> ''")
                ->order('entity_id ASC')
                ->limit(1)
        );

        return is_array($row) ? new TrackingState(
            (string) $row['affiliate_code'],
            $row['referral_link'] !== null ? (string) $row['referral_link'] : null,
            null,
            null,
            null
        ) : null;
    }

    /**
     * Make sure the order has a tracking row holding the order.placed snapshot
     * and its quote id. Reuses the row captured for the order's quote (not
     * linked yet when the order is committed), else inserts one.
     *
     * @param int $orderId
     * @param int $quoteId
     * @param string $affiliateCode Empty for coupon-only / "all orders" orders
     * @param string|null $referralLink Link of the same click as the code (ignored without a code)
     * @param string $fingerprint
     * @param string $state
     * @param string $status
     * @return void
     */
    public function markTracked(
        int $orderId,
        int $quoteId,
        string $affiliateCode,
        ?string $referralLink,
        string $fingerprint,
        string $state,
        string $status
    ): void {
        $connection = $this->getConnection();
        $snapshot = ['fingerprint' => $fingerprint, 'last_state' => $state, 'last_status' => $status];
        if ($quoteId > 0) {
            $snapshot['quote_id'] = $quoteId;
        }

        $entityId = $connection->fetchOne(
            $connection->select()->from($this->getMainTable(), ['entity_id'])->where('order_id = ?', $orderId)
        );
        if ($entityId === false && $quoteId > 0) {
            $entityId = $connection->fetchOne(
                $connection->select()
                    ->from($this->getMainTable(), ['entity_id'])
                    ->where('quote_id = ?', $quoteId)
                    ->where('order_id IS NULL')
                    ->order('entity_id ASC')
                    ->limit(1)
            );
        }

        if ($entityId !== false) {
            $connection->update(
                $this->getMainTable(),
                $snapshot + ['order_id' => $orderId],
                ['entity_id = ?' => (int) $entityId]
            );
            return;
        }

        $connection->insert(
            $this->getMainTable(),
            $snapshot + [
                'order_id' => $orderId,
                'affiliate_code' => $affiliateCode !== '' ? $affiliateCode : null,
                'referral_link' => $affiliateCode !== '' ? $referralLink : null,
            ]
        );
    }

    /**
     * Atomically move the order's snapshot to a new fingerprint. Only one
     * caller wins per change, however many triggers fire for it.
     *
     * @param int $orderId
     * @param string $fingerprint
     * @param string $state
     * @param string $status
     * @return bool True when this call changed the fingerprint
     */
    public function claimFingerprint(int $orderId, string $fingerprint, string $state, string $status): bool
    {
        $affected = $this->getConnection()->update(
            $this->getMainTable(),
            ['fingerprint' => $fingerprint, 'last_state' => $state, 'last_status' => $status],
            [
                'order_id = ?' => $orderId,
                '(fingerprint IS NULL OR fingerprint <> ?)' => $fingerprint,
            ]
        );

        return (int) $affected === 1;
    }
}
