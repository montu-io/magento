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
use Montu\Affiliate\Model\AffiliateTrackingFactory;
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
     * @var AffiliateTrackingFactory
     */
    private AffiliateTrackingFactory $trackingFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param AffiliateTrackingResource $resource
     * @param CollectionFactory $collectionFactory
     * @param AffiliateTrackingFactory $trackingFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        AffiliateTrackingResource $resource,
        CollectionFactory $collectionFactory,
        AffiliateTrackingFactory $trackingFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->trackingFactory = $trackingFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function saveByQuoteId(int $quoteId, string $affiliateCode): void
    {
        try {
            // Check if already exists for this quote
            if ($this->existsForQuote($quoteId)) {
                $this->logger->debug(
                    '[Montu Affiliate] Tracking already exists for quote',
                    ['quote_id' => $quoteId]
                );
                return;
            }

            $tracking = $this->trackingFactory->create();
            $tracking->setQuoteId($quoteId);
            $tracking->setAffiliateCode($affiliateCode);
            
            $this->resource->save($tracking);

            $this->logger->info(
                '[Montu Affiliate] Saved affiliate tracking for quote',
                ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Error saving affiliate tracking: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'exception' => $e]
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function linkToOrder(int $quoteId, int $orderId): void
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('quote_id', $quoteId);
            
            /** @var AffiliateTracking $tracking */
            $tracking = $collection->getFirstItem();

            if ($tracking->getEntityId()) {
                $tracking->setOrderId($orderId);
                $this->resource->save($tracking);

                $this->logger->info(
                    '[Montu Affiliate] Linked affiliate tracking to order',
                    [
                        'quote_id' => $quoteId,
                        'order_id' => $orderId,
                        'affiliate_code' => $tracking->getAffiliateCode()
                    ]
                );
            }
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
    public function getByQuoteId(int $quoteId): ?string
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('quote_id', $quoteId);
            
            /** @var AffiliateTracking $tracking */
            $tracking = $collection->getFirstItem();

            return $tracking->getEntityId() ? $tracking->getAffiliateCode() : null;
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Error getting by quote ID: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'exception' => $e]
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
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Error checking quote existence: ' . $e->getMessage(),
                ['quote_id' => $quoteId, 'exception' => $e]
            );
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function existsForOrder(int $orderId): bool
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('order_id', $orderId);
            
            return $collection->getSize() > 0;
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Error checking order existence: ' . $e->getMessage(),
                ['order_id' => $orderId, 'exception' => $e]
            );
            return false;
        }
    }
}

