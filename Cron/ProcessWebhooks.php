<?php
/**
 * Montu Affiliate Tracking Extension - Process Webhooks Cron
 *
 * Delivers the queued events. Runs in the "montu_affiliate" cron group on the
 * schedule from Stores > Configuration > Montu > Delivery Queue & Logging
 * (default every 5 minutes). Per run it:
 *   1. recovers events stuck in "processing";
 *   2. delivers a batch, order events first, then oldest first, never sending an
 *      event while an earlier event of the same order is not delivered (per-order
 *      FIFO); only rows of store views with the module and webhook enabled are
 *      considered;
 *   3. builds the catalog events waiting to be built (Service\CatalogEventBuilder),
 *      which the next run delivers.
 * Old events are deleted by the daily job Cron\CleanupEvents.
 *
 * Runs never overlap: Magento's cron runner already locks each job. A run never
 * starts a send after 4 minutes, and stops after 5 sends in a row that could not
 * reach Montu, so a slow or hanging endpoint never keeps the job busy for long.
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
use Montu\Affiliate\Service\CatalogEventBuilder;
use Montu\Affiliate\Service\WebhookService;
use Psr\Log\LoggerInterface;

class ProcessWebhooks
{
    private const RESULT_SENT = 'sent';
    private const RESULT_FAILED = 'failed';

    /**
     * Montu could not be reached: no response, a timeout, 429 or 5xx
     */
    private const RESULT_UNREACHABLE = 'unreachable';

    /**
     * A run stops starting new sends after this long, so it always ends within a
     * 5-minute schedule; what is left goes in the next run
     */
    private const RUN_TIME_LIMIT_SECONDS = 240;

    /**
     * A run stops after this many sends in a row that could not reach Montu: the
     * endpoint is down or hanging, and each try could wait the whole request
     * timeout. The events are retried by the next runs as usual.
     */
    private const MAX_CONSECUTIVE_UNREACHABLE = 5;

    /**
     * An event left in "processing" longer than this (a run that died) is
     * counted as a failed attempt
     */
    private const PROCESSING_TIMEOUT_MINUTES = 15;

    /**
     * Time a run spends building catalog events at most. Rows with nothing to
     * send (no change, a variant...) cost no delivery, so far more are read than
     * sent; at about 0.4 s per 1000 products, a full catalog feed of 100,000
     * products is cleared in a run or two.
     */
    private const CATALOG_BUILD_SECONDS = 60;

    private Config $config;
    private WebhookLogRepository $webhookLogRepository;
    private WebhookService $webhookService;
    private CatalogEventBuilder $catalogEventBuilder;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param WebhookLogRepository $webhookLogRepository
     * @param WebhookService $webhookService
     * @param CatalogEventBuilder $catalogEventBuilder
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        WebhookLogRepository $webhookLogRepository,
        WebhookService $webhookService,
        CatalogEventBuilder $catalogEventBuilder,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->webhookService = $webhookService;
        $this->catalogEventBuilder = $catalogEventBuilder;
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
            $storeIds = $this->getEnabledStoreIds();
            // Delivery first: whatever happens while building catalog events (even a
            // fatal error) never holds back the events already queued, orders included.
            $this->deliverBatch($storeIds);
            $this->buildCatalogEvents($storeIds);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Cron error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Build the catalog events waiting to be built; the next run delivers them
     *
     * @param int[] $storeIds
     * @return void
     */
    private function buildCatalogEvents(array $storeIds): void
    {
        try {
            $result = $this->catalogEventBuilder->build(
                $this->config->getBatchSize(),
                microtime(true) + self::CATALOG_BUILD_SECONDS,
                $storeIds
            );
            if ($result['built'] > 0 || $result['skipped'] > 0) {
                $this->logger->info('[Montu Affiliate] Built catalog events', $result);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error building catalog events: ' . $e->getMessage(),
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
     * @param int[] $storeIds Store views with the module and webhook enabled
     * @return void
     */
    private function deliverBatch(array $storeIds): void
    {
        $limit = $this->config->getBatchSize();
        $deadline = microtime(true) + self::RUN_TIME_LIMIT_SECONDS;
        $handled = [];
        $sent = 0;
        $failed = 0;
        $unreachableInARow = 0;
        $stopReason = null;

        while (count($handled) < $limit && $stopReason === null) {
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
                if (microtime(true) >= $deadline) {
                    $stopReason = 'time limit reached';
                    break;
                }
                $handled[] = (int) $log->getEntityId();
                $result = $this->process($log);
                $sent += $result === self::RESULT_SENT ? 1 : 0;
                $failed += $result === self::RESULT_SENT ? 0 : 1;
                $progressed = $progressed || $result === self::RESULT_SENT;

                $unreachableInARow = $result === self::RESULT_UNREACHABLE ? $unreachableInARow + 1 : 0;
                if ($unreachableInARow >= self::MAX_CONSECUTIVE_UNREACHABLE) {
                    $stopReason = 'Montu unreachable';
                    break;
                }
            }

            // Only a delivery can unblock more events; otherwise stop.
            if (!$progressed) {
                break;
            }
        }

        if ($stopReason !== null) {
            $this->logger->warning(
                '[Montu Affiliate] Delivery run stopped early (' . $stopReason . '); the rest goes in the next run',
                ['sent' => $sent, 'failed' => $failed]
            );
        } elseif ($handled !== []) {
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
     * Deliver a single event
     *
     * @param WebhookLog $log
     * @return string One of the RESULT_* constants
     */
    private function process(WebhookLog $log): string
    {
        $payload = $log->getPayload();
        if ($log->getEventId() === '' || $payload === null) {
            // Queued by module v1, which stored no payload: nothing to send.
            $log->setStatus(WebhookLog::STATUS_MAX_RETRIES)
                ->setErrorMessage('Recorded by module v1 without a payload; not sent')
                ->setNextRetryAt(null);
            $this->webhookLogRepository->save($log);

            return self::RESULT_FAILED;
        }

        $unreachable = false;
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
            // A store view without endpoint or key is not Montu being unreachable.
            $unreachable = empty($result['not_configured']) && $this->isUnreachable($result['status_code']);
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
            $unreachable = true;
            $log->markAsFailed('Exception: ' . $e->getMessage());
            $this->webhookLogRepository->save($log);
            $this->logger->error(
                '[Montu Affiliate] Webhook exception',
                ['event_id' => $log->getEventId(), 'exception' => $e]
            );
        }

        return $unreachable ? self::RESULT_UNREACHABLE : self::RESULT_FAILED;
    }

    /**
     * Montu did not answer, timed out, throttled (429) or failed (5xx), as opposed
     * to rejecting this one event (4xx)
     *
     * @param mixed $statusCode
     * @return bool
     */
    private function isUnreachable($statusCode): bool
    {
        $code = (int) $statusCode;

        return $code === 0 || $code === 429 || $code >= 500;
    }

}
