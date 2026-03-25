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
use Montu\Affiliate\Model\WebhookLogFactory;
use Psr\Log\LoggerInterface;

class WebhookLogRepository
{
    private WebhookLogResource $resource;
    private CollectionFactory $collectionFactory;
    private WebhookLogFactory $webhookLogFactory;
    private LoggerInterface $logger;

    public function __construct(
        WebhookLogResource $resource,
        CollectionFactory $collectionFactory,
        WebhookLogFactory $webhookLogFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->webhookLogFactory = $webhookLogFactory;
        $this->logger = $logger;
    }

    /**
     * Create a new webhook log entry
     *
     * @param int $orderId
     * @param string $affiliateCode
     * @param int $maxRetries
     * @return WebhookLog
     */
    public function create(int $orderId, string $affiliateCode, int $maxRetries = 5): WebhookLog
    {
        // Check if already exists
        $existing = $this->getByOrderId($orderId);
        if ($existing) {
            return $existing;
        }

        $webhookLog = $this->webhookLogFactory->create();
        $webhookLog->setOrderId($orderId)
                   ->setAffiliateCode($affiliateCode)
                   ->setStatus(WebhookLog::STATUS_PENDING)
                   ->setMaxRetries($maxRetries)
                   ->setRetryCount(0);

        $this->resource->save($webhookLog);

        $this->logger->info(
            '[Montu Affiliate] Created webhook log',
            ['order_id' => $orderId, 'webhook_log_id' => $webhookLog->getEntityId()]
        );

        return $webhookLog;
    }

    /**
     * Get webhook log by order ID
     *
     * @param int $orderId
     * @return WebhookLog|null
     */
    public function getByOrderId(int $orderId): ?WebhookLog
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);

        /** @var WebhookLog $webhookLog */
        $webhookLog = $collection->getFirstItem();

        return $webhookLog->getEntityId() ? $webhookLog : null;
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
     * Get all webhooks that need processing:
     * - status = 'pending' (new webhooks)
     * - status = 'failed' AND next_retry_at <= now (ready for retry)
     *
     * @param int $limit
     * @return WebhookLog[]
     */
    public function getPendingWebhooks(int $limit = 50): array
    {
        $collection = $this->collectionFactory->create();
        $now = date('Y-m-d H:i:s');

        // Build condition: pending OR (failed AND ready for retry)
        $collection->getSelect()->where(
            "status = ? OR (status = ? AND next_retry_at <= ?)",
            WebhookLog::STATUS_PENDING,
            WebhookLog::STATUS_FAILED,
            $now
        );

        $collection->setPageSize($limit)
                   ->setOrder('created_at', 'ASC');

        return $collection->getItems();
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        $tableName = $collection->getMainTable();

        $select = $connection->select()
            ->from($tableName, [
                'status',
                'count' => new \Zend_Db_Expr('COUNT(*)')
            ])
            ->group('status');

        $results = $connection->fetchPairs($select);

        return [
            'pending' => (int) ($results[WebhookLog::STATUS_PENDING] ?? 0),
            'processing' => (int) ($results[WebhookLog::STATUS_PROCESSING] ?? 0),
            'success' => (int) ($results[WebhookLog::STATUS_SUCCESS] ?? 0),
            'failed' => (int) ($results[WebhookLog::STATUS_FAILED] ?? 0),
            'max_retries' => (int) ($results[WebhookLog::STATUS_MAX_RETRIES] ?? 0),
        ];
    }
}
