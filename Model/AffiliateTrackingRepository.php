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
    public function saveByQuoteId(int $quoteId, string $affiliateCode): void
    {
        try {
            // A plain insert, no model save: save() opens and rolls back its own
            // transaction, which would doom a caller's one on failure.
            $this->resource->getConnection()->insert(
                $this->resource->getMainTable(),
                ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode]
            );

            $this->logger->debug(
                '[Montu Affiliate] Saved affiliate tracking for quote',
                ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode]
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
    public function existsForQuote(int $quoteId): bool
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('quote_id', $quoteId);
            
            return $collection->getSize() > 0;
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error checking quote existence: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'exception' => $e]
            );
            return false;
        }
    }
}

