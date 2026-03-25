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
}

