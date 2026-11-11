<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Status Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\WebhookLog;

/**
 * Delivery statuses, for the Event Log filter
 */
class WebhookStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => WebhookLog::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => WebhookLog::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => WebhookLog::STATUS_SUCCESS, 'label' => __('Delivered')],
            ['value' => WebhookLog::STATUS_FAILED, 'label' => __('Failed (will retry)')],
            ['value' => WebhookLog::STATUS_MAX_RETRIES, 'label' => __('Failed (max attempts)')],
        ];
    }
}
