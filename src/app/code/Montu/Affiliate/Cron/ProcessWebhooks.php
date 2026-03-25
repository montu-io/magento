<?php
/**
 * Montu Affiliate Tracking Extension - Process Webhooks Cron
 *
 * Simple cron job that processes pending and failed webhooks.
 * Runs every minute by default.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Cron;

use Magento\Sales\Api\OrderRepositoryInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\WebhookLogRepository;
use Montu\Affiliate\Service\WebhookService;
use Psr\Log\LoggerInterface;

class ProcessWebhooks
{
    /**
     * Max webhooks to process per cron run
     */
    private const MAX_WEBHOOKS_PER_RUN = 50;

    private Config $config;
    private WebhookLogRepository $webhookLogRepository;
    private WebhookService $webhookService;
    private OrderRepositoryInterface $orderRepository;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        WebhookLogRepository $webhookLogRepository,
        WebhookService $webhookService,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->webhookService = $webhookService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * Execute cron job - process pending and retry-ready webhooks
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isWebhookEnabled()) {
            return;
        }

        try {
            // Get pending webhooks
            $pendingWebhooks = $this->webhookLogRepository->getPendingWebhooks(self::MAX_WEBHOOKS_PER_RUN);
            $count = count($pendingWebhooks);

            if ($count === 0) {
                $this->logger->debug('[Montu Affiliate] No pending webhooks to process');
                return;
            }

            $this->logger->info('[Montu Affiliate] Processing webhooks', ['count' => $count]);

            $success = 0;
            $failed = 0;

            foreach ($pendingWebhooks as $webhookLog) {
                $result = $this->processWebhook($webhookLog);
                if ($result) {
                    $success++;
                } else {
                    $failed++;
                }
            }

            $this->logger->info(
                '[Montu Affiliate] Webhook processing completed',
                ['success' => $success, 'failed' => $failed]
            );

        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Cron error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Process a single webhook
     *
     * @param WebhookLog $webhookLog
     * @return bool
     */
    private function processWebhook(WebhookLog $webhookLog): bool
    {
        $orderId = $webhookLog->getOrderId();

        try {
            // Mark as processing
            $webhookLog->markAsProcessing();
            $this->webhookLogRepository->save($webhookLog);

            // Load order
            try {
                $order = $this->orderRepository->get($orderId);
            } catch (\Exception $e) {
                $webhookLog->markAsFailed('Order not found: ' . $e->getMessage());
                $this->webhookLogRepository->save($webhookLog);
                $this->logger->error('[Montu Affiliate] Order not found', ['order_id' => $orderId]);
                return false;
            }

            // Send webhook
            $result = $this->webhookService->sendOrderWebhookWithResponse(
                $order,
                $webhookLog->getAffiliateCode()
            );

            if ($result['success']) {
                $webhookLog->markAsSuccess(
                    $result['status_code'],
                    $result['response_body']
                );
                $this->webhookLogRepository->save($webhookLog);

                $this->logger->info(
                    '[Montu Affiliate] Webhook sent successfully',
                    ['order_id' => $orderId, 'status_code' => $result['status_code']]
                );
                return true;
            } else {
                $webhookLog->markAsFailed(
                    $result['error_message'],
                    $result['status_code'],
                    $result['response_body']
                );
                $this->webhookLogRepository->save($webhookLog);

                $this->logger->warning(
                    '[Montu Affiliate] Webhook failed',
                    [
                        'order_id' => $orderId,
                        'error' => $result['error_message'],
                        'retry_count' => $webhookLog->getRetryCount(),
                        'can_retry' => $webhookLog->canRetry(),
                        'next_retry' => $webhookLog->getNextRetryAt()
                    ]
                );
                return false;
            }

        } catch (\Exception $e) {
            $webhookLog->markAsFailed('Exception: ' . $e->getMessage());
            $this->webhookLogRepository->save($webhookLog);

            $this->logger->error(
                '[Montu Affiliate] Webhook exception',
                ['order_id' => $orderId, 'exception' => $e]
            );
            return false;
        }
    }
}

