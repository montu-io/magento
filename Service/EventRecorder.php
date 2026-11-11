<?php
/**
 * Montu Affiliate Tracking Extension - Event Recorder
 *
 * Turns a committed order change into an outbox row: resolves the affiliate
 * code, decides whether the event applies (qualification for order.placed,
 * "tracked" for the others), de-duplicates, snapshots the payload and queues
 * it. Delivery is Cron\ProcessWebhooks; nothing here talks to Montu.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\EventType;
use Montu\Affiliate\Model\OrderFingerprint;
use Montu\Affiliate\Model\OrderQualifier;
use Montu\Affiliate\Model\Payload\EventContext;
use Montu\Affiliate\Model\Payload\PayloadBuilder;
use Montu\Affiliate\Model\ResourceModel\AffiliateTracking as TrackingResource;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Montu\Affiliate\Model\TrackingState;
use Montu\Affiliate\Model\WebhookLog;

class EventRecorder
{
    /**
     * order.placed is only recorded for orders created within this window, so
     * saving an old, never-reported order does not report it as new.
     */
    private const PLACED_MAX_AGE_SECONDS = 86400;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var OrderQualifier
     */
    private OrderQualifier $qualifier;

    /**
     * @var OrderFingerprint
     */
    private OrderFingerprint $fingerprint;

    /**
     * @var PayloadBuilder
     */
    private PayloadBuilder $payloadBuilder;

    /**
     * @var TrackingResource
     */
    private TrackingResource $trackingResource;

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
     * @param Config $config
     * @param OrderQualifier $qualifier
     * @param OrderFingerprint $fingerprint
     * @param PayloadBuilder $payloadBuilder
     * @param TrackingResource $trackingResource
     * @param WebhookLogResource $webhookLogResource
     * @param ResourceConnection $resourceConnection
     * @param IdentityGeneratorInterface $identityGenerator
     */
    public function __construct(
        Config $config,
        OrderQualifier $qualifier,
        OrderFingerprint $fingerprint,
        PayloadBuilder $payloadBuilder,
        TrackingResource $trackingResource,
        WebhookLogResource $webhookLogResource,
        ResourceConnection $resourceConnection,
        IdentityGeneratorInterface $identityGenerator
    ) {
        $this->config = $config;
        $this->qualifier = $qualifier;
        $this->fingerprint = $fingerprint;
        $this->payloadBuilder = $payloadBuilder;
        $this->trackingResource = $trackingResource;
        $this->webhookLogResource = $webhookLogResource;
        $this->resourceConnection = $resourceConnection;
        $this->identityGenerator = $identityGenerator;
    }

    /**
     * A committed order save: order.placed for an order not tracked yet,
     * order.updated for a tracked one
     *
     * @param Order $order
     * @param string $trigger The Magento event, stored as the event's "trigger"
     * @return void
     * @throws \Exception
     */
    public function recordOrderSaved(Order $order, string $trigger): void
    {
        if (!$this->canRecord($order)) {
            return;
        }

        $storeId = (int) $order->getStoreId();
        $tracking = $this->trackingResource->findByOrderId((int) $order->getId());

        if ($tracking === null || !$tracking->isTracked()) {
            if ($this->config->isEventTypeEnabled(EventType::ORDER_PLACED, $storeId)) {
                $this->recordPlaced($order, $trigger, $tracking);
            }
            return;
        }

        if ($this->config->isEventTypeEnabled(EventType::ORDER_UPDATED, $storeId)) {
            $this->recordUpdated($order, $trigger, $tracking);
        }
    }

    /**
     * A committed credit memo: creditmemo.created, once per credit memo of a
     * tracked order
     *
     * @param Creditmemo $creditmemo
     * @param string $trigger
     * @return void
     * @throws \Exception
     */
    public function recordCreditmemoSaved(Creditmemo $creditmemo, string $trigger): void
    {
        $order = $creditmemo->getOrder();
        if (!$creditmemo->getId() || !$order instanceof Order || !$this->canRecord($order)
            || !$this->config->isEventTypeEnabled(EventType::CREDITMEMO_CREATED, (int) $order->getStoreId())
        ) {
            return;
        }

        $tracking = $this->trackingResource->findByOrderId((int) $order->getId());
        if ($tracking === null || !$tracking->isTracked()) {
            return;
        }

        $dedupeKey = 'creditmemo.created:' . (int) $creditmemo->getId();
        if ($this->webhookLogResource->hasDedupeKey($dedupeKey)) {
            return;
        }

        $context = new EventContext(
            EventType::CREDITMEMO_CREATED,
            $trigger,
            $this->identityGenerator->generateId(),
            $this->now(),
            $order,
            (string) $tracking->getAffiliateCode(),
            $tracking->getLastState(),
            $tracking->getLastStatus(),
            $creditmemo
        );
        $row = $this->outboxRow($context, $dedupeKey);

        $this->transactional(fn (): bool => $this->webhookLogResource->insertEvent($row));
    }

    /**
     * Module and webhook enabled for the order's store view, and no foreign
     * transaction open. Commit-time events run once the outermost transaction
     * committed, so one is never open in practice; if it ever is, the module
     * stays out of it rather than risk the caller's transaction.
     *
     * @param Order $order
     * @return bool
     */
    private function canRecord(Order $order): bool
    {
        $storeId = (int) $order->getStoreId();

        return $order->getId()
            && $this->config->isEnabled($storeId)
            && $this->config->isWebhookEnabled($storeId)
            && $this->resourceConnection->getConnection()->getTransactionLevel() === 0;
    }

    /**
     * Resolve the affiliate code (tracking row by order, else the code captured
     * on the order's quote: sales_order_save_commit_after fires before
     * CopyAffiliateToOrder links the row), qualify, then queue order.placed and
     * start tracking the order.
     *
     * @param Order $order
     * @param string $trigger
     * @param TrackingState|null $tracking
     * @return void
     * @throws \Exception
     */
    private function recordPlaced(Order $order, string $trigger, ?TrackingState $tracking): void
    {
        if ($this->isTooOld($order)) {
            return;
        }

        $code = (string) ($tracking !== null ? $tracking->getAffiliateCode() : null);
        if ($code === '') {
            $code = (string) $this->trackingResource->findCodeByQuoteId((int) $order->getQuoteId());
        }
        if (!$this->qualifier->qualifies($order, $code)) {
            return;
        }

        $context = new EventContext(
            EventType::ORDER_PLACED,
            $trigger,
            $this->identityGenerator->generateId(),
            $this->now(),
            $order,
            $code
        );
        $row = $this->outboxRow($context, 'order.placed:' . (int) $order->getId());

        $this->transactional(function () use ($order, $code, $row): bool {
            $inserted = $this->webhookLogResource->insertEvent($row);

            // Also when the event was already queued (a concurrent save): the
            // order must end up tracked.
            $this->trackingResource->markTracked(
                (int) $order->getId(),
                (int) $order->getQuoteId(),
                $code,
                $this->fingerprint->forOrder($order),
                (string) $order->getState(),
                (string) $order->getStatus()
            );

            return $inserted;
        });
    }

    /**
     * Queue order.updated when the order's fingerprint changed since the last
     * recorded event. The atomic fingerprint update decides which of several
     * simultaneous saves wins.
     *
     * @param Order $order
     * @param string $trigger
     * @param TrackingState $tracking
     * @return void
     * @throws \Exception
     */
    private function recordUpdated(Order $order, string $trigger, TrackingState $tracking): void
    {
        $fingerprint = $this->fingerprint->forOrder($order);
        if ($tracking->getFingerprint() === $fingerprint) {
            return;
        }

        $eventId = $this->identityGenerator->generateId();
        $context = new EventContext(
            EventType::ORDER_UPDATED,
            $trigger,
            $eventId,
            $this->now(),
            $order,
            (string) $tracking->getAffiliateCode(),
            $tracking->getLastState(),
            $tracking->getLastStatus()
        );
        $row = $this->outboxRow($context, 'order.updated:' . $eventId);

        $this->transactional(function () use ($order, $fingerprint, $row): bool {
            $claimed = $this->trackingResource->claimFingerprint(
                (int) $order->getId(),
                $fingerprint,
                (string) $order->getState(),
                (string) $order->getStatus()
            );

            return $claimed && $this->webhookLogResource->insertEvent($row);
        });
    }

    /**
     * Snapshot the event into an outbox row
     *
     * @param EventContext $context
     * @param string $dedupeKey
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function outboxRow(EventContext $context, string $dedupeKey): array
    {
        $payload = json_encode(
            $this->payloadBuilder->build($context),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );

        return [
            'event_id' => $context->eventId,
            'event_type' => $context->eventType,
            'order_id' => (int) $context->order->getId(),
            'store_id' => $context->getStoreId(),
            'affiliate_code' => $context->affiliateCode,
            'trigger_event' => $context->trigger,
            'dedupe_key' => $dedupeKey,
            'payload' => $payload,
            'status' => WebhookLog::STATUS_PENDING,
            'retry_count' => 0,
            'max_retries' => $this->config->getMaxRetries($context->getStoreId()),
        ];
    }

    /**
     * Run the tracking update and the outbox insert in one short transaction of
     * the module's own, so they commit or roll back together
     *
     * @param callable $work
     * @return bool
     * @throws \Throwable
     */
    private function transactional(callable $work): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $result = (bool) $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Current UTC time with microseconds, e.g. 2026-11-12T13:05:00.123456+00:00
     *
     * @return string
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isTooOld(Order $order): bool
    {
        $createdAt = strtotime((string) $order->getCreatedAt() . ' UTC');

        return $createdAt !== false && (time() - $createdAt) > self::PLACED_MAX_AGE_SECONDS;
    }
}
