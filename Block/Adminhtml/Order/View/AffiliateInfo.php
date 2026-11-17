<?php
/**
 * Montu Affiliate Tracking Extension - Admin Order View Block
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\TrackingState;

class AffiliateInfo extends Template
{
    /**
     * @var Registry
     */
    private Registry $coreRegistry;

    /**
     * @var AffiliateTrackingRepositoryInterface
     */
    private AffiliateTrackingRepositoryInterface $trackingRepository;

    /**
     * @var TrackingState|null
     */
    private ?TrackingState $tracking = null;

    /**
     * @var bool
     */
    private bool $trackingLoaded = false;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param AffiliateTrackingRepositoryInterface $trackingRepository
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        AffiliateTrackingRepositoryInterface $trackingRepository,
        array $data = []
    ) {
        $this->coreRegistry = $registry;
        $this->trackingRepository = $trackingRepository;
        parent::__construct($context, $data);
    }

    /**
     * Get current order
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->coreRegistry->registry('current_order');
    }

    /**
     * Get affiliate code from tracking table
     *
     * @return string|null
     */
    public function getAffiliateCode(): ?string
    {
        $tracking = $this->getTracking();

        return $tracking !== null ? $tracking->getAffiliateCode() : null;
    }

    /**
     * Check if affiliate code exists
     *
     * @return bool
     */
    public function hasAffiliateCode(): bool
    {
        return !empty($this->getAffiliateCode());
    }

    /**
     * Get referral link from tracking table
     *
     * @return string|null
     */
    public function getReferralLink(): ?string
    {
        $tracking = $this->getTracking();

        return $tracking !== null ? $tracking->getReferralLink() : null;
    }

    /**
     * Check if a referral link exists
     *
     * @return bool
     */
    public function hasReferralLink(): bool
    {
        return !empty($this->getReferralLink());
    }

    /**
     * The order's tracking row, looked up once per view
     *
     * @return TrackingState|null
     */
    private function getTracking(): ?TrackingState
    {
        if (!$this->trackingLoaded) {
            $this->trackingLoaded = true;
            $order = $this->getOrder();

            if ($order && $order->getId()) {
                $this->tracking = $this->trackingRepository->findByOrderId((int) $order->getId());
            }
        }

        return $this->tracking;
    }
}
