<?php
/**
 * Montu Affiliate Tracking Extension - Process Webhooks Cron
 *
 * Delivers the queued events. Runs in the "montu_affiliate" cron group on the
 * schedule from Stores > Configuration > Montu > Delivery Queue & Logging
 * (default every 5 minutes). Per run it:
 *   1. recovers events stuck in "processing";
 *   2. delivers a batch, oldest first, never sending an event while an earlier
 *      event of the same order is not delivered (per-order FIFO); only rows of
 *      store views with the module and webhook enabled are considered;
 *   3. deletes delivered events past the retention period.
 *
 * Runs never overlap: Magento's cron runner already locks each job.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\WebhookLogRepository;
use Montu\Affiliate\Service\WebhookService;
use Psr\Log\LoggerInterface;

class ProcessWebhooks
{
    private const RESULT_SENT = 'sent';
    private const RESULT_FAILED = 'failed';

    /**
     * An event left in "processing" longer than this (a run that died) is
     * counted as a failed attempt
     */
    private const PROCESSING_TIMEOUT_MINUTES = 15;

    private Config $config;
    private WebhookLogRepository $webhookLogRepository;
    private WebhookService $webhookService;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param WebhookLogRepository $webhookLogRepository
     * @param WebhookService $webhookService
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        WebhookLogRepository $webhookLogRepository,
        WebhookService $webhookService,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->webhookService = $webhookService;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Execute cron job
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->recoverStuck();
            $this->deliverBatch();
            $this->purgeDelivered();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Cron error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Count events left in "processing" by a run that died as a failed attempt
     *
     * @return void
     */
    private function recoverStuck(): void
    {
        foreach ($this->webhookLogRepository->getStuck(self::PROCESSING_TIMEOUT_MINUTES) as $log) {
            $log->markAsFailed(
                'Timed out while processing (the delivery run did not finish)'
            );
            $this->webhookLogRepository->save($log);
            $this->logger->warning(
                '[Montu Affiliate] Recovered an event stuck in processing',
                ['event_id' => $log->getEventId(), 'status' => $log->getStatus()]
            );
        }
    }

    /**
     * Deliver up to the batch size. Delivering an event can free the next event
     * of the same order, so keep asking for ready events until none is left.
     *
     * @return void
     */
    private function deliverBatch(): void
    {
        $limit = $this->config->getBatchSize();
        $storeIds = $this->getEnabledStoreIds();
        $handled = [];
        $sent = 0;
        $failed = 0;

        while (count($handled) < $limit) {
            $batch = $this->webhookLogRepository->getReadyBatch(
                $limit - count($handled),
                $storeIds,
                $handled
            );
            if ($batch === []) {
                break;
            }

            $progressed = false;
            foreach ($batch as $log) {
                $handled[] = (int) $log->getEntityId();
                $result = $this->process($log);
                $sent += $result === self::RESULT_SENT ? 1 : 0;
                $failed += $result === self::RESULT_FAILED ? 1 : 0;
                $progressed = $progressed || $result === self::RESULT_SENT;
            }

            // Only a delivery can unblock more events; otherwise stop.
            if (!$progressed) {
                break;
            }
        }

        if ($handled !== []) {
            $this->logger->info('[Montu Affiliate] Delivery run completed', ['sent' => $sent, 'failed' => $failed]);
        }
    }

    /**
     * Store views with the module and the webhook enabled
     *
     * @return int[]
     */
    private function getEnabledStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if ($this->config->isEnabled($storeId) && $this->config->isWebhookEnabled($storeId)) {
                $ids[] = $storeId;
            }
        }

        return $ids;
    }

    /**
     * Delete delivered events past the retention period
     *
     * @return void
     */
    private function purgeDelivered(): void
    {
        $days = $this->config->getRetentionDays();
        if ($days > 0) {
            $deleted = $this->webhookLogRepository->purgeDelivered($days);
            if ($deleted > 0) {
                $this->logger->info('[Montu Affiliate] Deleted delivered events', ['count' => $deleted]);
            }
        }
    }

    /**
     * Deliver a single event
     *
     * @param WebhookLog $log
     * @return string One of the RESULT_* constants
     */
    private function process(WebhookLog $log): string
    {
        $payload = $log->getPayload();
        if ($payload === null) {
            // Queued by module v1, which stored no payload: nothing to send.
            $log->setStatus(WebhookLog::STATUS_MAX_RETRIES)
                ->setErrorMessage('Recorded by module v1 without a payload; not sent')
                ->setNextRetryAt(null);
            $this->webhookLogRepository->save($log);

            return self::RESULT_FAILED;
        }

        try {
            $log->markAsProcessing();
            $this->webhookLogRepository->save($log);

            $result = $this->webhookService->send($log, $payload);

            if ($result['success']) {
                $log->markAsSuccess((int) $result['status_code'], $result['response_body']);
                $this->webhookLogRepository->save($log);
                return self::RESULT_SENT;
            }

            $log->markAsFailed(
                $result['error_message'],
                $result['status_code'],
                $result['response_body']
            );
            $this->webhookLogRepository->save($log);
            $this->logger->warning(
                '[Montu Affiliate] Webhook failed',
                [
                    'event_id' => $log->getEventId(),
                    'error' => $result['error_message'],
                    'retry_count' => $log->getRetryCount(),
                    'can_retry' => $log->canRetry(),
                    'next_retry' => $log->getNextRetryAt()
                ]
            );
        } catch (\Throwable $e) {
            $log->markAsFailed('Exception: ' . $e->getMessage());
            $this->webhookLogRepository->save($log);
            $this->logger->error(
                '[Montu Affiliate] Webhook exception',
                ['order_id' => $log->getOrderId(), 'exception' => $e]
            );
        }

        return self::RESULT_FAILED;
    }
}
