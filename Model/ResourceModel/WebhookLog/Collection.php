<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Collection
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel\WebhookLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_webhook_log_collection';

    /**
     * Initialize collection
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(WebhookLog::class, WebhookLogResource::class);
    }

    /**
     * Filter by pending retries (failed status with next_retry_at in the past)
     *
     * @return $this
     */
    public function addPendingRetryFilter(): self
    {
        $this->addFieldToFilter('status', WebhookLog::STATUS_FAILED)
             ->addFieldToFilter('next_retry_at', ['lteq' => date('Y-m-d H:i:s')]);
        
        return $this;
    }
}

