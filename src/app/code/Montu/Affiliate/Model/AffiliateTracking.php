<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Tracking Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Framework\Model\AbstractModel;
use Montu\Affiliate\Model\ResourceModel\AffiliateTracking as AffiliateTrackingResource;

class AffiliateTracking extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_tracking';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AffiliateTrackingResource::class);
    }

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get order ID
     *
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        $id = $this->getData('order_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Set order ID
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    /**
     * Get quote ID
     *
     * @return int|null
     */
    public function getQuoteId(): ?int
    {
        $id = $this->getData('quote_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Set quote ID
     *
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId(int $quoteId): self
    {
        return $this->setData('quote_id', $quoteId);
    }

    /**
     * Get affiliate code
     *
     * @return string|null
     */
    public function getAffiliateCode(): ?string
    {
        return $this->getData('affiliate_code');
    }

    /**
     * Set affiliate code
     *
     * @param string $affiliateCode
     * @return $this
     */
    public function setAffiliateCode(string $affiliateCode): self
    {
        return $this->setData('affiliate_code', $affiliateCode);
    }

    /**
     * Get created at timestamp
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData('created_at');
    }

    /**
     * Get updated at timestamp
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData('updated_at');
    }
}

