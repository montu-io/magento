<?php
/**
 * Montu Affiliate Tracking Extension - Catalog Event Builder
 *
 * Runs in the cron, before delivery. Takes the catalog events waiting to be
 * built (outbox rows with an event id and no payload, see
 * Model\Catalog\ChangeRecorder) and builds each from the product or category as
 * it is now, a batch per store view. Only as many are built as delivery can take
 * (catalog events already built and not yet delivered count), so while Montu is
 * unreachable changes keep collapsing into the waiting rows instead of piling up
 * as stale snapshots.
 *
 * - Nothing to send (the subject was deleted, is a variant sent within its
 *   parent, or its store view / event type was turned off): closed as "skipped"
 *   with the reason.
 * - No change: a CSV import rewrites every row it is given, so a full catalog
 *   feed records every product. Each event's content is fingerprinted
 *   (content_hash, updated_at left out: the importer bumps it on every row). The
 *   reference is the last event Montu received (or is receiving) for that
 *   product / category in that store view; an equal fingerprint is closed as
 *   "skipped" without being sent. Events of the subject still waiting for
 *   delivery (pending, failed) are never trusted as the reference: a newer
 *   event replaces them ("superseded"), so nothing is lost if they would fail.
 *   Cron\CleanupEvents keeps the last delivered event per subject (without its
 *   payload) so the reference outlives the retention.
 * - A category no longer in the store view's tree is sent as collection.deleted.
 * - Each row's collapse key (dedupe_key) is released before the data is read,
 *   so a change committed after that read always gets a new row; every write is
 *   conditional on the row still waiting, so a deletion recorded meanwhile is
 *   never overwritten.
 * - A batch that fails is retried row by row; a row that keeps failing uses up
 *   its attempts (max_retries) and never blocks the others.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\EventType;
use Montu\Affiliate\Model\Payload\CatalogPayloadBuilder;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Montu\Affiliate\Model\WebhookLog;
use Psr\Log\LoggerInterface;

class CatalogEventBuilder
{
    /**
     * Rows read per batch (one product collection per store view and batch)
     */
    private const BATCH = 100;

    /**
     * Largest body sent (the events gateway queues messages of up to 256 KB). A
     * bigger product is sent without its descriptions (description_omitted).
     */
    private const MAX_PAYLOAD_BYTES = 200000;

    /**
     * content_hash of a waiting row that must be sent even if its content did not
     * change (an admin requeue)
     */
    public const FORCE_SEND = '-';

    /**
     * content_hash of a deletion: Montu no longer has the subject, so whatever
     * comes next is sent
     */
    public const DELETED = 'deleted';

    /**
     * @var CatalogPayloadBuilder
     */
    private CatalogPayloadBuilder $payloadBuilder;

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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param CatalogPayloadBuilder $payloadBuilder
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param WebhookLogResource $webhookLogResource
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        CatalogPayloadBuilder $payloadBuilder,
        Config $config,
        StoreManagerInterface $storeManager,
        WebhookLogResource $webhookLogResource,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->payloadBuilder = $payloadBuilder;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->webhookLogResource = $webhookLogResource;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Build waiting catalog events until $deliverable of them wait for delivery,
     * or until $deadline (skipped rows cost no delivery, so many more are read)
     *
     * @param int $deliverable Catalog events one delivery run can take
     * @param float $deadline Unix time (microtime) after which no new batch is started
     * @param int[] $storeIds Store views delivery sends for (module and webhook enabled)
     * @return array{built: int, skipped: int}
     */
    public function build(int $deliverable, float $deadline, array $storeIds): array
    {
        $built = 0;
        $skipped = 0;
        $lastId = 0;
        $room = $deliverable - $this->builtNotDelivered($storeIds, $deliverable);
        while ($built < $room && microtime(true) < $deadline) {
            // $room is a soft limit (a batch may overshoot it): rows with nothing to send
            // cost no room, so batches never shrink while unchanged rows are cleared.
            $rows = $this->waitingRows($lastId, self::BATCH);
            if ($rows === []) {
                break;
            }
            $lastId = (int) end($rows)['entity_id'];
            // Taken before the data is read: a change committed after the read
            // (a new row) always carries a later occurred_at than this snapshot.
            $occurredAt = $this->payloadBuilder->now();
            $this->release($rows);

            foreach ($this->groups($rows) as [$store, $subject, $group]) {
                $result = $this->buildGroup($store, $subject, $group, $occurredAt);
                $built += $result['built'];
                $skipped += $result['skipped'];
            }
        }

        return ['built' => $built, 'skipped' => $skipped];
    }

    /**
     * Catalog events built and still waiting for delivery in the store views
     * delivery sends for (rows of a store view turned off never count), counted
     * up to $limit
     *
     * @param int[] $storeIds
     * @param int $limit
     * @return int
     */
    private function builtNotDelivered(array $storeIds, int $limit): int
    {
        if ($storeIds === []) {
            return 0;
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->select()
            ->from($this->webhookLogResource->getMainTable(), [new Expression('1')])
            ->where('status IN (?)', [WebhookLog::STATUS_PENDING, WebhookLog::STATUS_FAILED])
            ->where('subject_id IS NOT NULL')
            ->where('payload IS NOT NULL')
            ->where('store_id IN (?)', array_map('intval', $storeIds))
            ->limit($limit);

        return (int) $connection->fetchOne($connection->select()->from(['t' => $rows], [new Expression('COUNT(*)')]));
    }

    /**
     * Waiting catalog rows, oldest first
     *
     * @param int $afterId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function waitingRows(int $afterId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->webhookLogResource->getMainTable(),
                    [
                        'entity_id', 'event_id', 'event_type', 'store_id', 'subject_id', 'trigger_event',
                        'dedupe_key', 'content_hash', 'retry_count', 'max_retries',
                    ]
                )
                ->where('status = ?', WebhookLog::STATUS_PENDING)
                ->where('payload IS NULL')
                ->where('event_id IS NOT NULL')
                ->where('subject_id IS NOT NULL')
                ->where('entity_id > ?', $afterId)
                ->order('entity_id ASC')
                ->limit($limit)
        );
    }

    /**
     * Release the rows' collapse keys before their data is read. Updated by key,
     * in key order, so it locks in the same order as ChangeRecorder's upserts
     * (rows that became a deletion meanwhile keep theirs)
     *
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function release(array $rows): void
    {
        $keys = array_values(array_filter(array_map('strval', array_column($rows, 'dedupe_key'))));
        if ($keys === []) {
            return;
        }
        sort($keys);
        $this->resourceConnection->getConnection()->update(
            $this->webhookLogResource->getMainTable(),
            ['dedupe_key' => null],
            ['dedupe_key IN (?)' => $keys, 'payload IS NULL']
        );
    }

    /**
     * Rows grouped by store view and subject (product / collection)
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{0: Store|null, 1: string, 2: array<int, array<string, mixed>>}>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $subject = str_starts_with((string) $row['event_type'], 'product.') ? 'product' : 'collection';
            $groups[(int) $row['store_id'] . ':' . $subject][] = $row;
        }

        $result = [];
        foreach ($groups as $key => $group) {
            [$storeId, $subject] = explode(':', $key);
            try {
                $store = $this->storeManager->getStore((int) $storeId);
            } catch (\Throwable $e) {
                $store = null;
            }
            $result[] = [$store instanceof Store ? $store : null, $subject, $group];
        }

        return $result;
    }

    /**
     * Build a group; if that fails, build its rows one by one so a single bad
     * row cannot block the others
     *
     * @param Store|null $store
     * @param string $subject product or collection
     * @param array<int, array<string, mixed>> $rows
     * @param string $occurredAt
     * @return array{built: int, skipped: int}
     */
    private function buildGroup(?Store $store, string $subject, array $rows, string $occurredAt): array
    {
        try {
            return $this->store($store, $subject, $rows, $this->bodies($store, $subject, $rows), $occurredAt);
        } catch (\Throwable $e) {
            if (count($rows) === 1) {
                $this->countFailure($rows[0], $e);
                return ['built' => 0, 'skipped' => 0];
            }
        }

        $total = ['built' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $result = $this->buildGroup($store, $subject, [$row], $occurredAt);
            $total['built'] += $result['built'];
            $total['skipped'] += $result['skipped'];
        }

        return $total;
    }

    /**
     * Body per subject id, or the reason not to send it
     *
     * @param Store|null $store
     * @param string $subject
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>|string>
     */
    private function bodies(?Store $store, string $subject, array $rows): array
    {
        $ids = array_values(array_unique(array_map('intval', array_column($rows, 'subject_id'))));
        if ($store === null) {
            return array_fill_keys($ids, 'The store view no longer exists');
        }

        return $subject === 'product'
            ? $this->payloadBuilder->products($store, $ids)
            : $this->payloadBuilder->collections($store, $ids);
    }

    /**
     * Store the built payloads and close the rows with nothing to send, in one
     * transaction; each write only touches a row still waiting with the same event
     *
     * @param Store|null $store
     * @param string $subject
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>|string> $bodies
     * @param string $occurredAt
     * @return array{built: int, skipped: int}
     * @throws \Throwable
     */
    private function store(?Store $store, string $subject, array $rows, array $bodies, string $occurredAt): array
    {
        $connection = $this->resourceConnection->getConnection();
        $built = 0;
        $skipped = 0;
        $history = $store === null ? [] : $this->history($store, $subject, $rows);
        $connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $eventType = (string) $row['event_type'];
                $body = $bodies[(int) $row['subject_id']] ?? 'The subject no longer exists';
                if ($store !== null && !$this->config->sendsAnyOf([$eventType], (int) $store->getId())) {
                    $body = 'This event type or the webhook was turned off for the store view';
                }
                // Only a move takes a category out of a tree it was in; other saves of a
                // category in another tree (multi-root installs) are simply not sent.
                if ($body === CatalogPayloadBuilder::OUT_OF_TREE && $eventType === EventType::COLLECTION_UPDATED
                    && (string) $row['trigger_event'] === 'category_move'
                    && $this->config->isEventTypeEnabled(EventType::COLLECTION_DELETED, (int) $store->getId())
                ) {
                    $eventType = EventType::COLLECTION_DELETED;
                    $body = ['id' => (int) $row['subject_id']];
                }

                $subjectId = (int) $row['subject_id'];
                $before = $this->stateBefore($history[$subjectId] ?? [], (int) $row['entity_id']);
                $hash = null;
                if (is_array($body)) {
                    $hash = $eventType === EventType::COLLECTION_DELETED ? self::DELETED : $this->hash($body);
                    if ($row['content_hash'] !== self::FORCE_SEND
                        && $before['delivered'] === $hash
                        && $before['waiting'] === []
                    ) {
                        $body = 'No change since the last event sent';
                    }
                }

                $encoded = is_string($body) ? null : $this->encode($eventType, $row, $store, $subject, $body, $occurredAt);
                if ($encoded === null) {
                    $reason = is_string($body) ? $body : 'Larger than the gateway accepts, even without descriptions';
                    $skipped += $this->updateWaiting(
                        $row,
                        ['status' => WebhookLog::STATUS_SKIPPED, 'error_message' => 'Not sent: ' . $reason]
                    );
                    continue;
                }
                $stored = $this->updateWaiting(
                    $row,
                    ['event_type' => $eventType, 'payload' => $encoded, 'content_hash' => $hash]
                );
                if ($stored) {
                    // This event carries the current content: older updates of the subject
                    // still waiting for delivery are not sent any more (deletions always are).
                    $this->supersede(
                        array_column(
                            array_filter($before['waiting'], static fn (array $w): bool => $w[1] !== self::DELETED),
                            0
                        ),
                        (int) $row['entity_id']
                    );
                    $history[$subjectId][] = [(int) $row['entity_id'], WebhookLog::STATUS_PENDING, (string) $hash];
                }
                $built += $stored;
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return ['built' => $built, 'skipped' => $skipped];
    }

    /**
     * Built and delivered events of the rows' subjects in the store view (one
     * query), oldest first, per subject id: [entity_id, status, content_hash]
     *
     * @param Store $store
     * @param string $subject product or collection
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, array{0: int, 1: string, 2: string}>>
     */
    private function history(Store $store, string $subject, array $rows): array
    {
        $connection = $this->resourceConnection->getConnection();
        $history = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->webhookLogResource->getMainTable(), ['entity_id', 'subject_id', 'status', 'content_hash'])
                ->where('subject_id IN (?)', array_map('intval', array_column($rows, 'subject_id')))
                ->where('store_id = ?', (int) $store->getId())
                ->where('event_type LIKE ?', $subject . '.%')
                ->where('content_hash IS NOT NULL')
                ->where('content_hash <> ?', self::FORCE_SEND)
                ->where('status IN (?)', [
                    WebhookLog::STATUS_SUCCESS,
                    WebhookLog::STATUS_PROCESSING,
                    WebhookLog::STATUS_PENDING,
                    WebhookLog::STATUS_FAILED,
                ])
                ->order('entity_id ASC')
        ) as $event) {
            $history[(int) $event['subject_id']][] = [
                (int) $event['entity_id'],
                (string) $event['status'],
                (string) $event['content_hash'],
            ];
        }

        return $history;
    }

    /**
     * What Montu has of a subject as of a row: the content of the newest older
     * event delivered (or being delivered), and the older built events still
     * waiting for delivery after it. Only events older than the row count: a
     * newer one (a deletion recorded meanwhile) is delivered after it anyway.
     *
     * @param array<int, array{0: int, 1: string, 2: string}> $events
     * @param int $entityId
     * @return array{delivered: string|null, waiting: array<int, array{0: int, 1: string}>}
     */
    private function stateBefore(array $events, int $entityId): array
    {
        $state = ['delivered' => null, 'waiting' => []];
        foreach ($events as [$id, $status, $hash]) {
            if ($id >= $entityId) {
                break;
            }
            if (in_array($status, [WebhookLog::STATUS_SUCCESS, WebhookLog::STATUS_PROCESSING], true)) {
                $state = ['delivered' => $hash, 'waiting' => []];
            } else {
                $state['waiting'][] = [$id, $hash];
            }
        }

        return $state;
    }

    /**
     * Close built events that wait for delivery (pending / failed) because a newer
     * event of the same subject replaces them
     *
     * @param int[] $entityIds
     * @param int $byEntityId
     * @return void
     */
    private function supersede(array $entityIds, int $byEntityId): void
    {
        if ($entityIds === []) {
            return;
        }
        $this->resourceConnection->getConnection()->update(
            $this->webhookLogResource->getMainTable(),
            [
                'status' => WebhookLog::STATUS_SKIPPED,
                'next_retry_at' => null,
                'error_message' => 'Not sent: superseded by the newer event #' . $byEntityId,
            ],
            [
                'entity_id IN (?)' => $entityIds,
                'status IN (?)' => [WebhookLog::STATUS_PENDING, WebhookLog::STATUS_FAILED],
                'content_hash <> ?' => self::DELETED,
            ]
        );
    }

    /**
     * Fingerprint of an event's content, without updated_at (the importer bumps
     * it on every row it is given, changed or not)
     *
     * @param array<string, mixed> $body
     * @return string
     * @throws \JsonException
     */
    private function hash(array $body): string
    {
        $strip = static function (array $data) use (&$strip): array {
            unset($data['updated_at']);
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $strip($value);
                }
            }

            return $data;
        };

        return sha1($this->payloadBuilder->encode($strip($body)));
    }

    /**
     * The JSON body of an event, without the descriptions if it is too large for
     * the gateway (null if still too large)
     *
     * @param string $eventType
     * @param array<string, mixed> $row
     * @param Store $store
     * @param string $subject
     * @param array<string, mixed> $body
     * @param string $occurredAt
     * @return string|null
     * @throws \JsonException
     */
    private function encode(
        string $eventType,
        array $row,
        Store $store,
        string $subject,
        array $body,
        string $occurredAt
    ): ?string {
        $envelope = $this->payloadBuilder->envelope(
            $eventType,
            (string) $row['event_id'],
            $occurredAt,
            (string) $row['trigger_event'],
            $store
        );
        $encoded = $this->payloadBuilder->encode($envelope + [$subject => $body]);
        if (strlen($encoded) <= self::MAX_PAYLOAD_BYTES || $subject !== 'product') {
            return strlen($encoded) <= self::MAX_PAYLOAD_BYTES ? $encoded : null;
        }

        $body['description'] = null;
        $body['short_description'] = null;
        $body['description_omitted'] = true;
        $encoded = $this->payloadBuilder->encode($envelope + [$subject => $body]);

        return strlen($encoded) <= self::MAX_PAYLOAD_BYTES ? $encoded : null;
    }

    /**
     * A row that failed to build: count the attempt, close it once they are used up
     *
     * @param array<string, mixed> $row
     * @param \Throwable $e
     * @return void
     */
    private function countFailure(array $row, \Throwable $e): void
    {
        $attempts = (int) $row['retry_count'] + 1;
        $exhausted = $attempts >= (int) $row['max_retries'];
        $this->updateWaiting($row, [
            'retry_count' => $attempts,
            'status' => $exhausted ? WebhookLog::STATUS_MAX_RETRIES : WebhookLog::STATUS_PENDING,
            'error_message' => 'Could not build the event: ' . mb_substr($e->getMessage(), 0, 500),
        ]);
        $this->logger->error(
            '[Montu Affiliate] Could not build a catalog event' . ($exhausted ? ' (attempts used up)' : ' (retried next run)')
            . ': ' . $e->getMessage(),
            ['entity_id' => $row['entity_id'], 'subject_id' => $row['subject_id'], 'exception' => $e]
        );
    }

    /**
     * Update a row only if it still waits to be built as the same event
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     * @return int Rows updated (0 when it changed meanwhile)
     */
    private function updateWaiting(array $row, array $data): int
    {
        return (int) $this->resourceConnection->getConnection()->update(
            $this->webhookLogResource->getMainTable(),
            $data,
            ['entity_id = ?' => (int) $row['entity_id'], 'event_id = ?' => (string) $row['event_id'], 'payload IS NULL']
        );
    }
}
