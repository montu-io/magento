<?php
/**
 * Montu Affiliate Tracking Extension - Send Order Webhook Observer
 *
 * This observer saves the webhook to the log table for async processing by cron.
 * No complex queue system needed - just save and let cron handle it.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\WebhookLogRepository;
use Psr\Log\LoggerInterface;

class SendOrderWebhook implements ObserverInterface
{
    private Config $config;
    private WebhookLogRepository $webhookLogRepository;
    private AffiliateTrackingRepositoryInterface $trackingRepository;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        WebhookLogRepository $webhookLogRepository,
        AffiliateTrackingRepositoryInterface $trackingRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->trackingRepository = $trackingRepository;
        $this->logger = $logger;
    }

    /**
     * Execute observer - save webhook to log table for cron processing
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() ||
            !$this->config->isWebhookEnabled() ||
            !$this->config->shouldSendOnOrderPlaced()) {
            return;
        }

        try {
            /** @var Order|null $order */
            $order = $observer->getEvent()->getOrder();
            
            // checkout_submit_all_after may pass orders as array
            if (!$order) {
                $orders = $observer->getEvent()->getOrders();
                if (is_array($orders) && !empty($orders)) {
                    $order = reset($orders);
                }
            }

            if (!$order || !$order->getId()) {
                return;
            }

            $orderId = (int) $order->getId();
            $affiliateCode = $this->trackingRepository->getByOrderId($orderId);

            if (!$affiliateCode) {
                $this->logger->debug(
                    '[Montu Affiliate] Skipping webhook - no affiliate code for order',
                    ['order_id' => $orderId, 'order_increment_id' => $order->getIncrementId()]
                );
                return;
            }

            // Save to webhook log table - cron will process it
            $this->webhookLogRepository->create($orderId, $affiliateCode);

            $this->logger->info(
                '[Montu Affiliate] Webhook scheduled for order',
                [
                    'order_id' => $orderId,
                    'order_increment_id' => $order->getIncrementId(),
                    'affiliate_code' => $affiliateCode
                ]
            );

        } catch (\Exception $e) {
            // Log error but don't block the order process
            $this->logger->error(
                '[Montu Affiliate] Error scheduling webhook: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
