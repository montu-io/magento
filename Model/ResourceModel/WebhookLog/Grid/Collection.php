<?php
/**
 * Montu Affiliate Tracking Extension - Event Log Grid Collection
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel\WebhookLog\Grid;

use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Data source of the admin Event Log: the outbox joined to the order number
 */
class Collection extends SearchResult
{
    /**
     * Number of characters of the last error shown in the grid
     */
    private const ERROR_PREVIEW_LENGTH = 160;

    /**
     * Filters are mapped to the table they belong to: both tables have columns
     * such as created_at.
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $mainTableFields = [
            'entity_id', 'event_type', 'order_id', 'subject_id', 'store_id', 'status', 'created_at', 'last_attempt_at', 'next_retry_at',
        ];
        foreach ($mainTableFields as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        $this->addFilterToMap('increment_id', 'sales_order.increment_id');
        $this->addFilterToMap('error_preview', 'main_table.error_message');
    }

    /**
     * Select only the grid columns (the payload is large and not shown) and
     * join the order number.
     *
     * @return void
     */
    protected function _renderFiltersBefore()
    {
        $this->getSelect()
            ->reset(Select::COLUMNS)
            ->columns([
                'entity_id',
                'event_type',
                'order_id',
                'subject_id',
                'store_id',
                'affiliate_code',
                'status',
                'retry_count',
                'max_retries',
                'http_status_code',
                'error_preview' => new Expression(
                    'LEFT(main_table.error_message, ' . self::ERROR_PREVIEW_LENGTH . ')'
                ),
                'trigger_event',
                'created_at',
                'last_attempt_at',
                'next_retry_at',
            ])
            ->joinLeft(
                ['sales_order' => $this->getTable('sales_order')],
                'sales_order.entity_id = main_table.order_id',
                ['increment_id' => 'increment_id']
            );

        parent::_renderFiltersBefore();
    }
}
