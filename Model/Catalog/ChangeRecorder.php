<?php
/**
 * Montu Affiliate Tracking Extension - Catalog Change Recorder
 *
 * Writes catalog changes to the outbox (montu_affiliate_webhook_log) as events
 * waiting to be built: one row per subject and store view that sends the event
 * type, without a payload (the cron builds it from the data as it is then).
 * While a row waits, its dedupe_key "catalog:{store}:{product|category}:{id}"
 * makes every further change of the subject collapse into it, so a product
 * saved fifty times between two runs is sent once. Deletions are complete when
 * recorded (the subject is gone) and replace a waiting change.
 *
 * Everything is written with multi-row INSERT ... ON DUPLICATE KEY UPDATE, in
 * chunks, never one query per product.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Catalog;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\EventType;
use Montu\Affiliate\Model\Payload\CatalogPayloadBuilder;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Service\CatalogEventBuilder;
use Psr\Log\LoggerInterface;

class ChangeRecorder
{
    public const PRODUCT = 'product';
    public const CATEGORY = 'category';

    private const CHUNK = 500;

    /**
     * Attempts of a write that hits a deadlock or a lock wait timeout
     */
    private const WRITE_ATTEMPTS = 3;

    /**
     * Event types per subject: created, updated, deleted
     */
    private const TYPES = [
        self::PRODUCT => [EventType::PRODUCT_CREATED, EventType::PRODUCT_UPDATED, EventType::PRODUCT_DELETED],
        self::CATEGORY => [EventType::COLLECTION_CREATED, EventType::COLLECTION_UPDATED, EventType::COLLECTION_DELETED],
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var WebhookLogResource
     */
    private WebhookLogResource $webhookLogResource;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var IdentityGeneratorInterface
     */
    private IdentityGeneratorInterface $identityGenerator;

    /**
     * @var CatalogPayloadBuilder
     */
    private CatalogPayloadBuilder $payloadBuilder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Store views that send at least one catalog event, read once per request
     *
     * @var Store[]|null
     */
    private ?array $stores = null;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param WebhookLogResource $webhookLogResource
     * @param ResourceConnection $resourceConnection
     * @param IdentityGeneratorInterface $identityGenerator
     * @param CatalogPayloadBuilder $payloadBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        WebhookLogResource $webhookLogResource,
        ResourceConnection $resourceConnection,
        IdentityGeneratorInterface $identityGenerator,
        CatalogPayloadBuilder $payloadBuilder,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->webhookLogResource = $webhookLogResource;
        $this->resourceConnection = $resourceConnection;
        $this->identityGenerator = $identityGenerator;
        $this->payloadBuilder = $payloadBuilder;
        $this->logger = $logger;
    }

    /**
     * Some store view sends a catalog event. Observers check this first, so a
     * store without catalog sync pays nothing.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->stores() !== [];
    }

    /**
     * @param int[] $createdIds
     * @param int[] $updatedIds
     * @param string $trigger The Magento event
     * @return void
     */
    public function productsChanged(array $createdIds, array $updatedIds, string $trigger): void
    {
        $this->write(self::PRODUCT, $createdIds, $updatedIds, $trigger);
    }

    /**
     * @param int[] $createdIds
     * @param int[] $updatedIds
     * @param string $trigger
     * @return void
     */
    public function categoriesChanged(array $createdIds, array $updatedIds, string $trigger): void
    {
        $this->write(self::CATEGORY, $createdIds, $updatedIds, $trigger);
    }

    /**
     * @param array<int, string|null> $skus Product id => SKU (null when unknown)
     * @param string $trigger
     * @return void
     */
    public function productsDeleted(array $skus, string $trigger): void
    {
        $this->writeDeleted(self::PRODUCT, $skus, $trigger);
    }

    /**
     * @param int[] $ids
     * @param string $trigger
     * @return void
     */
    public function categoriesDeleted(array $ids, string $trigger): void
    {
        $this->writeDeleted(self::CATEGORY, array_fill_keys(array_map('intval', $ids), null), $trigger);
    }

    /**
     * Send a catalog event again (admin "Requeue"): a new waiting row, built from
     * the current data and sent even if its content did not change. It collapses
     * into a waiting row of the subject, if there is one; the requeued row itself
     * is left as it is.
     *
     * @param string $eventType The requeued event's type (created / updated)
     * @param int $subjectId
     * @param int $storeId
     * @return void
     */
    public function resend(string $eventType, int $subjectId, int $storeId): void
    {
        $subject = str_starts_with($eventType, 'product.') ? self::PRODUCT : self::CATEGORY;
        $row = $this->row($eventType, $subject, $subjectId, $this->storeManager->getStore($storeId), 'admin_requeue', null);
        $row['content_hash'] = CatalogEventBuilder::FORCE_SEND;
        $this->upsert([$row], ['content_hash' => new Expression('VALUES(content_hash)')]);
    }

    /**
     * Waiting rows for created / updated subjects
     *
     * @param string $subject
     * @param int[] $createdIds
     * @param int[] $updatedIds
     * @param string $trigger
     * @return void
     */
    private function write(string $subject, array $createdIds, array $updatedIds, string $trigger): void
    {
        $created = array_fill_keys($this->clean($createdIds), true);
        $updated = array_diff($this->clean($updatedIds), array_keys($created));
        if ($created === [] && $updated === []) {
            return;
        }

        [$createdType, $updatedType] = self::TYPES[$subject];
        $rows = [];
        foreach ($this->stores() as $store) {
            $storeId = (int) $store->getId();
            $sendsCreated = $this->config->isEventTypeEnabled($createdType, $storeId);
            $sendsUpdated = $this->config->isEventTypeEnabled($updatedType, $storeId);
            foreach (array_keys($created) as $id) {
                if ($sendsCreated) {
                    $rows[] = $this->row($createdType, $subject, (int) $id, $store, $trigger, null);
                }
            }
            foreach ($updated as $id) {
                if ($sendsUpdated) {
                    $rows[] = $this->row($updatedType, $subject, (int) $id, $store, $trigger, null);
                }
            }
        }

        // Collapsing into a waiting row: created stays created, a deletion is
        // never turned back into an update.
        $this->upsert($rows, [
            'event_type' => new Expression(
                "IF(event_type LIKE '%.created' OR event_type LIKE '%.deleted', event_type, VALUES(event_type))"
            ),
            'trigger_event' => new Expression("IF(event_type LIKE '%.deleted', trigger_event, VALUES(trigger_event))"),
        ]);
    }

    /**
     * Deletion events, complete when recorded: they replace a waiting change of
     * the subject (its own event id and payload included)
     *
     * @param string $subject
     * @param array<int, string|null> $skus
     * @param string $trigger
     * @return void
     */
    private function writeDeleted(string $subject, array $skus, string $trigger): void
    {
        $deletedType = self::TYPES[$subject][2];
        $rows = [];
        foreach ($this->stores() as $store) {
            if (!$this->config->isEventTypeEnabled($deletedType, (int) $store->getId())) {
                continue;
            }
            foreach ($skus as $id => $sku) {
                if ((int) $id <= 0) {
                    continue;
                }
                $body = $subject === self::PRODUCT
                    ? ['product' => ['id' => (int) $id, 'sku' => $sku]]
                    : ['collection' => ['id' => (int) $id]];
                $rows[] = $this->row($deletedType, $subject, (int) $id, $store, $trigger, $body);
            }
        }

        $this->upsert($rows, [
            'event_type' => new Expression('VALUES(event_type)'),
            'event_id' => new Expression('VALUES(event_id)'),
            'payload' => new Expression('VALUES(payload)'),
            'content_hash' => new Expression('VALUES(content_hash)'),
            'trigger_event' => new Expression('VALUES(trigger_event)'),
            'status' => new Expression('VALUES(status)'),
            'retry_count' => new Expression('VALUES(retry_count)'),
            'max_retries' => new Expression('VALUES(max_retries)'),
            'error_message' => new Expression('NULL'),
        ]);
    }

    /**
     * @param string $eventType
     * @param string $subject
     * @param int $subjectId
     * @param Store $store
     * @param string $trigger
     * @param array<string, mixed>|null $body Complete body (deletions), else built later
     * @return array<string, mixed>
     */
    private function row(
        string $eventType,
        string $subject,
        int $subjectId,
        Store $store,
        string $trigger,
        ?array $body
    ): array {
        $storeId = (int) $store->getId();
        $eventId = $this->identityGenerator->generateId();

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'order_id' => null,
            'subject_id' => $subjectId,
            'store_id' => $storeId,
            'affiliate_code' => '',
            'trigger_event' => $trigger,
            'dedupe_key' => 'catalog:' . $storeId . ':' . $subject . ':' . $subjectId,
            'payload' => $body === null ? null : $this->payloadBuilder->encode(
                $this->payloadBuilder->envelope($eventType, $eventId, $this->payloadBuilder->now(), $trigger, $store)
                + $body
            ),
            // A deletion resets the reference content: whatever comes next is sent.
            'content_hash' => $body === null ? null : CatalogEventBuilder::DELETED,
            'status' => WebhookLog::STATUS_PENDING,
            'retry_count' => 0,
            'max_retries' => $this->config->getMaxRetries($storeId),
        ];
    }

    /**
     * Multi-row upsert, never inside the caller's transaction: if one is open
     * (no core path does it today, a third-party one could), the rows are written
     * once it commits, and dropped with it if it rolls back. Rows are sorted by
     * key so concurrent writers lock in the same order; a deadlock or lock wait
     * timeout is retried (3 attempts in all).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, Expression> $onDuplicate
     * @return void
     */
    private function upsert(array $rows, array $onDuplicate): void
    {
        if ($rows === []) {
            return;
        }
        $connection = $this->resourceConnection->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $this->webhookLogResource->addCommitCallback(function () use ($rows, $onDuplicate): void {
                try {
                    $this->upsert($rows, $onDuplicate);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        '[Montu Affiliate] Error recording catalog changes after commit (suppressed): ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
            });
            return;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['dedupe_key'], (string) $b['dedupe_key']));
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            for ($attempt = 1;; $attempt++) {
                try {
                    $connection->insertOnDuplicate($this->webhookLogResource->getMainTable(), $chunk, $onDuplicate);
                    break;
                } catch (DeadlockException | LockWaitException $e) {
                    // Contention with the cron or another writer: wait a little and retry.
                    if ($attempt >= self::WRITE_ATTEMPTS) {
                        throw $e;
                    }
                    usleep(50000 * $attempt);
                }
            }
        }
    }

    /**
     * @param array<int, int|string> $ids
     * @return int[]
     */
    private function clean(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return Store[]
     */
    private function stores(): array
    {
        if ($this->stores === null) {
            $this->stores = [];
            foreach ($this->storeManager->getStores() as $store) {
                if ($this->config->sendsAnyOf(EventType::catalog(), (int) $store->getId())) {
                    $this->stores[] = $store;
                }
            }
        }

        return $this->stores;
    }
}
