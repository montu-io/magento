<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Repository
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Framework\DB\Sql\Expression;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Montu\Affiliate\Model\ResourceModel\WebhookLog\CollectionFactory;
use Psr\Log\LoggerInterface;

class WebhookLogRepository
{
    private const PURGE_BATCH_SIZE = 1000;
    private const PURGE_ALIAS = 'old';

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
     * Events ready to be delivered, order events first, then oldest first:
     * - status = 'pending' (new events)
     * - status = 'failed' AND next_retry_at <= now (ready for retry)
     * and only when every earlier event of the same order is finished:
     * 'success', or 'max_retries' (dead-lettered; every event carries the full
     * order, so holding the later ones back gains nothing). Per-order FIFO.
     * Catalog events have no order, so the FIFO never holds them back; those
     * still waiting to be built (no payload yet) are not returned.
     *
     * Only rows of the given store views are returned, so a store view with the
     * module or webhook disabled never takes batch slots (nor blocks other rows).
     * Rows queued by v1 (no event id) are always returned: the cron closes them
     * without sending, so they never block an order.
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
        $orders = $this->readyRows($limit, $storeIds, $excludeIds, true);
        $room = $limit - count($orders);

        return $room > 0 ? $orders + $this->readyRows($room, $storeIds, $excludeIds, false) : $orders;
    }

    /**
     * Ready rows of one kind, oldest first: order events (and rows queued by v1,
     * which have an order and no event id), or built catalog events. Two simple
     * queries instead of one sorted across both kinds, so a large catalog backlog
     * never slows down finding the order events.
     *
     * @param int $limit
     * @param int[] $storeIds
     * @param int[] $excludeIds
     * @param bool $orders
     * @return WebhookLog[]
     */
    private function readyRows(int $limit, array $storeIds, array $excludeIds, bool $orders): array
    {
        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        $now = date('Y-m-d H:i:s');

        $pendingCondition = $connection->quoteInto('main_table.status = ?', WebhookLog::STATUS_PENDING);
        $failedCondition = $connection->quoteInto('main_table.status = ?', WebhookLog::STATUS_FAILED)
            . ' AND ' . $connection->quoteInto('main_table.next_retry_at <= ?', $now);

        $select = $collection->getSelect();
        $select->where("({$pendingCondition}) OR ({$failedCondition})");

        $storeCondition = $storeIds !== []
            ? $connection->quoteInto('main_table.store_id IN (?)', array_map('intval', $storeIds))
            : '0 = 1';
        if ($orders) {
            $select->where('main_table.order_id IS NOT NULL');
            $select->where(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s AS earlier WHERE earlier.order_id = main_table.order_id'
                . ' AND earlier.entity_id < main_table.entity_id AND earlier.status NOT IN (%s))',
                $collection->getMainTable(),
                $connection->quote([WebhookLog::STATUS_SUCCESS, WebhookLog::STATUS_MAX_RETRIES])
            ));
            $select->where('main_table.event_id IS NULL OR ' . $storeCondition);
        } else {
            // Catalog events still waiting to be built (no payload) are not ready.
            $select->where('main_table.subject_id IS NOT NULL');
            $select->where('main_table.payload IS NOT NULL');
            $select->where($storeCondition);
        }

        if ($excludeIds !== []) {
            $collection->addFieldToFilter('main_table.entity_id', ['nin' => $excludeIds]);
        }
        $collection->setPageSize($limit)->setOrder('main_table.entity_id', 'ASC');

        return $collection->getItems();
    }

    /**
     * Put an event back in the queue (admin "Requeue"), touching only the
     * delivery columns. Catalog events (not deletions) are not requeued here:
     * ChangeRecorder::resend() queues a new event built from the current data.
     *
     * @param WebhookLog $log
     * @param int $maxRetries
     * @return bool False when the event is being delivered right now (left as is)
     */
    public function requeue(WebhookLog $log, int $maxRetries): bool
    {
        return (bool) $this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            [
                'status' => WebhookLog::STATUS_PENDING,
                'retry_count' => 0,
                'next_retry_at' => null,
                'max_retries' => $maxRetries,
            ],
            ['entity_id = ?' => (int) $log->getEntityId(), 'status <> ?' => WebhookLog::STATUS_PROCESSING]
        );
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
     * Delete finished events past their retention, in batches of 1000 by primary
     * key (never one big DELETE on the table), at most $maxBatches batches per
     * rule and call:
     * - delivered order events after $orderDays (0 = keep);
     * - catalog events after $catalogDays: skipped ones, and delivered ones that a
     *   newer delivered event of the same subject and store view replaced. The
     *   newest delivered event of each subject is kept, without its payload: its
     *   content_hash is the reference that keeps unchanged products from being
     *   sent again (CatalogEventBuilder);
     * - events that used up their attempts after $deadDays.
     *
     * @param int $orderDays
     * @param int $catalogDays
     * @param int $deadDays
     * @param int $maxBatches
     * @return int Rows deleted
     */
    public function purge(int $orderDays, int $catalogDays, int $deadDays, int $maxBatches): int
    {
        $deleted = 0;
        if ($orderDays > 0) {
            $deleted += $this->purgeWhere(
                ['status = ?' => WebhookLog::STATUS_SUCCESS, 'subject_id IS NULL'],
                $orderDays,
                $maxBatches
            );
        }
        $deleted += $this->purgeWhere(
            ['status = ?' => WebhookLog::STATUS_SKIPPED, 'subject_id IS NOT NULL'],
            $catalogDays,
            $maxBatches
        );
        $deleted += $this->purgeWhere(
            [
                'status = ?' => WebhookLog::STATUS_SUCCESS,
                'subject_id IS NOT NULL',
                sprintf(
                    "EXISTS (SELECT 1 FROM %s AS newer WHERE newer.subject_id = %s.subject_id"
                    . " AND newer.store_id = %s.store_id AND newer.entity_id > %s.entity_id"
                    . " AND newer.status = '%s' AND newer.content_hash IS NOT NULL"
                    . " AND newer.event_type LIKE CONCAT(SUBSTRING_INDEX(%s.event_type, '.', 1), '.%%'))",
                    $this->resource->getMainTable(),
                    self::PURGE_ALIAS,
                    self::PURGE_ALIAS,
                    self::PURGE_ALIAS,
                    WebhookLog::STATUS_SUCCESS,
                    self::PURGE_ALIAS
                ),
            ],
            $catalogDays,
            $maxBatches
        );
        $this->compactCatalogReferences($catalogDays, $maxBatches);
        $deleted += $this->purgeWhere(['status = ?' => WebhookLog::STATUS_MAX_RETRIES], $deadDays, $maxBatches);

        return $deleted;
    }

    /**
     * Drop the payload of the delivered catalog events kept as references (their
     * content_hash is all that is needed)
     *
     * @param int $days
     * @param int $maxBatches
     * @return void
     */
    private function compactCatalogReferences(int $days, int $maxBatches): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['entity_id'])
                    ->where('status = ?', WebhookLog::STATUS_SUCCESS)
                    ->where('subject_id IS NOT NULL')
                    ->where('payload IS NOT NULL')
                    // Deletions keep their (small) body: requeuing one resends it as it is.
                    ->where('event_type NOT LIKE ?', '%.deleted')
                    ->where('updated_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)')
                    ->limit(self::PURGE_BATCH_SIZE)
            );
            if ($ids === []) {
                return;
            }
            $connection->update(
                $table,
                ['payload' => null, 'response_body' => null],
                ['entity_id IN (?)' => array_map('intval', $ids)]
            );
            if (count($ids) < self::PURGE_BATCH_SIZE) {
                return;
            }
        }
    }

    /**
     * @param array<int|string, mixed> $conditions
     * @param int $days
     * @param int $maxBatches
     * @return int
     */
    private function purgeWhere(array $conditions, int $days, int $maxBatches): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $deleted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $select = $connection->select()
                ->from([self::PURGE_ALIAS => $table], ['entity_id'])
                ->where(self::PURGE_ALIAS . '.updated_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)')
                ->limit(self::PURGE_BATCH_SIZE);
            foreach ($conditions as $condition => $value) {
                is_int($condition) ? $select->where($value) : $select->where($condition, $value);
            }
            $ids = $connection->fetchCol($select);
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
