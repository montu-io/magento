<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Tracking Collection
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel\AffiliateTracking;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Montu\Affiliate\Model\AffiliateTracking;
use Montu\Affiliate\Model\ResourceModel\AffiliateTracking as AffiliateTrackingResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_tracking_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'affiliate_tracking_collection';

    /**
     * Initialize collection
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AffiliateTracking::class, AffiliateTrackingResource::class);
    }
}

