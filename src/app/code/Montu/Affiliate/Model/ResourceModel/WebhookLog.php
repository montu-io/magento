<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Resource Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WebhookLog extends AbstractDb
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_webhook_log_resource';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('montu_affiliate_webhook_log', 'entity_id');
    }
}

