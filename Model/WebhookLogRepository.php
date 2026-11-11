<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Repository
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Montu\Affiliate\Model\ResourceModel\WebhookLog\CollectionFactory;
use Psr\Log\LoggerInterface;

class WebhookLogRepository
{
    private const PURGE_BATCH_SIZE = 1000;
    private const PURGE_MAX_BATCHES = 20;

    private WebhookLogResource $resource;
    private CollectionFactory $collectionFactory;
    private LoggerInterface $logger;

    public function __construct(
        WebhookLogResource $resource,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
    }

    /**
     * Save webhook log
     *
     * @param WebhookLog $webhookLog
     * @return void
     */
    public function save(WebhookLog $webhookLog): void
    {
        $this->resource->save($webhookLog);
    }

    /**
     * Events ready to be delivered, oldest first:
     * - status = 'pending' (new events)
     * - status = 'failed' AND next_retry_at <= now (ready for retry)
     * and only when every earlier event of the same order is finished:
     * 'success', or 'max_retries' (dead-lettered; every event carries the full
     * order, so holding the later ones back gains nothing). Per-order FIFO.
     *
     * Only rows of the given store views are returned, so a store view with the
     * module or webhook disabled never takes batch slots (nor blocks other rows).
     * Rows queued by v1 (no payload, no store id) are always returned: the cron
     * closes them without sending, so they never block an order.
     *
     * @param int $limit
     * @param int[] $storeIds Store views that are enabled
     * @param int[] $excludeIds Rows already handled in this run
     * @return WebhookLog[]
     */
    public function getReadyBatch(
        int $limit,
        array $storeIds,
        array $excludeIds = []
    ): array {

        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        $now = date('Y-m-d H:i:s');

        $pendingCondition = $connection->quoteInto('main_table.status = ?', WebhookLog::STATUS_PENDING);
        $failedCondition = $connection->quoteInto('main_table.status = ?', WebhookLog::STATUS_FAILED)
            . ' AND ' . $connection->quoteInto('main_table.next_retry_at <= ?', $now);

        $select = $collection->getSelect();
        $select->where("({$pendingCondition}) OR ({$failedCondition})");
        $select->where(sprintf(
            'NOT EXISTS (SELECT 1 FROM %s AS earlier WHERE earlier.order_id = main_table.order_id'
            . ' AND earlier.entity_id < main_table.entity_id AND earlier.status NOT IN (%s))',
            $collection->getMainTable(),
            $connection->quote([WebhookLog::STATUS_SUCCESS, WebhookLog::STATUS_MAX_RETRIES])
        ));

        $storeConditions = ['main_table.payload IS NULL'];
        if ($storeIds !== []) {
            $storeConditions[] = $connection->quoteInto('main_table.store_id IN (?)', array_map('intval', $storeIds));
        }
        $select->where(implode(' OR ', $storeConditions));

        if ($excludeIds !== []) {
            $collection->addFieldToFilter('main_table.entity_id', ['nin' => $excludeIds]);
        }

        $collection->setPageSize($limit)->setOrder('main_table.entity_id', 'ASC');

        return $collection->getItems();
    }

    /**
     * Events stuck in 'processing' since before the given number of minutes
     * (a cron run that died mid-send)
     *
     * @param int $timeoutMinutes
     * @return WebhookLog[]
     */
    public function getStuck(int $timeoutMinutes): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', WebhookLog::STATUS_PROCESSING)
            ->addFieldToFilter(
                'last_attempt_at',
                ['lt' => date('Y-m-d H:i:s', strtotime('-' . $timeoutMinutes . ' minutes'))]
            );

        return $collection->getItems();
    }

    /**
     * Delete delivered events older than the retention period, in small
     * batches by primary key (never one big DELETE on the table), at most
     * PURGE_MAX_BATCHES batches per call; the next run continues.
     *
     * @param int $days
     * @return int Rows deleted
     */
    public function purgeDelivered(int $days): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $deleted = 0;

        for ($batch = 0; $batch < self::PURGE_MAX_BATCHES; $batch++) {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['entity_id'])
                    ->where('status = ?', WebhookLog::STATUS_SUCCESS)
                    ->where('updated_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)')
                    ->limit(self::PURGE_BATCH_SIZE)
            );
            if ($ids === []) {
                break;
            }

            $deleted += (int) $connection->delete($table, ['entity_id IN (?)' => array_map('intval', $ids)]);

            if (count($ids) < self::PURGE_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }
}
