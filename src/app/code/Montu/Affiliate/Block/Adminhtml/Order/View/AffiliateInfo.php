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
     * @var string|null
     */
    private ?string $affiliateCode = null;

    /**
     * @var bool
     */
    private bool $affiliateCodeLoaded = false;

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
        if (!$this->affiliateCodeLoaded) {
            $this->affiliateCodeLoaded = true;
            $order = $this->getOrder();
            
            if ($order && $order->getId()) {
                $this->affiliateCode = $this->trackingRepository->getByOrderId((int) $order->getId());
            }
        }
        
        return $this->affiliateCode;
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
}
