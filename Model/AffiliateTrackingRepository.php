<?php
/**
 * Montu Affiliate Tracking Extension - Repository Implementation
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\ResourceModel\AffiliateTracking as AffiliateTrackingResource;
use Montu\Affiliate\Model\ResourceModel\AffiliateTracking\CollectionFactory;
use Psr\Log\LoggerInterface;

class AffiliateTrackingRepository implements AffiliateTrackingRepositoryInterface
{
    /**
     * @var AffiliateTrackingResource
     */
    private AffiliateTrackingResource $resource;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param AffiliateTrackingResource $resource
     * @param CollectionFactory $collectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        AffiliateTrackingResource $resource,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function saveByQuoteId(int $quoteId, string $affiliateCode, ?string $referralLink): void
    {
        try {
            // Plain insert / update, no model save: save() opens and rolls back its
            // own transaction, which would doom a caller's one on failure.
            $connection = $this->resource->getConnection();
            $table = $this->resource->getMainTable();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($table, ['entity_id', 'affiliate_code', 'referral_link'])
                    ->where('quote_id = ?', $quoteId)
                    ->where('order_id IS NULL')
                    ->order('entity_id ASC')
                    ->limit(1)
            );

            // Only the row not yet linked to an order: an order's own row is never touched, so a
            // restored quote (failed redirect payment) gets a new row for its next click.
            if (!is_array($row)) {
                $connection->insert(
                    $table,
                    ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode, 'referral_link' => $referralLink]
                );
            } elseif ($row['affiliate_code'] !== $affiliateCode || $row['referral_link'] !== $referralLink) {
                // Last click wins: the code and its link are replaced together.
                $connection->update(
                    $table,
                    ['affiliate_code' => $affiliateCode, 'referral_link' => $referralLink],
                    ['entity_id = ?' => (int) $row['entity_id']]
                );
            } else {
                return;
            }

            $this->logger->debug(
                '[Montu Affiliate] Saved affiliate tracking for quote',
                ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode, 'referral_link' => $referralLink]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error saving affiliate tracking: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'exception' => $e]
            );
        }
    }

    /**
     * @inheritdoc
     *
     * Usually already done: EventRecorder links the quote's row when it
     * records order.placed, which happens before this runs.
     */
    public function linkToOrder(int $quoteId, int $orderId): void
    {
        try {
            if ($this->getByOrderId($orderId) !== null) {
                return;
            }

            /** @var AffiliateTracking $byQuote */
            $byQuote = $this->collectionFactory->create()
                ->addFieldToFilter('quote_id', $quoteId)
                ->addFieldToFilter('order_id', ['null' => true])
                ->getFirstItem();
            if ($byQuote->getEntityId()) {
                // A plain update, for the same reason as in saveByQuoteId().
                $this->resource->getConnection()->update(
                    $this->resource->getMainTable(),
                    ['order_id' => $orderId],
                    ['entity_id = ?' => (int) $byQuote->getEntityId()]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error linking to order: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'order_id' => $orderId, 'exception' => $e]
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getByOrderId(int $orderId): ?string
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('order_id', $orderId);
            
            /** @var AffiliateTracking $tracking */
            $tracking = $collection->getFirstItem();

            return $tracking->getEntityId() ? $tracking->getAffiliateCode() : null;
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error getting by order ID: ' . $e->getMessage(),
                ['order_id' => $orderId, 'exception' => $e]
            );
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function findByOrderId(int $orderId): ?TrackingState
    {
        try {
            return $this->resource->findByOrderId($orderId);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error finding tracking by order ID: ' . $e->getMessage(),
                ['order_id' => $orderId, 'exception' => $e]
            );
            return null;
        }
    }
}
