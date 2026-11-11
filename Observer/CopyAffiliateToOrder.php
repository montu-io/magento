<?php
/**
 * Montu Affiliate Tracking Extension - Copy Affiliate Code to Order Observer
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class CopyAffiliateToOrder implements ObserverInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var AffiliateTrackingRepositoryInterface
     */
    private AffiliateTrackingRepositoryInterface $trackingRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param AffiliateTrackingRepositoryInterface $trackingRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        AffiliateTrackingRepositoryInterface $trackingRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->trackingRepository = $trackingRepository;
        $this->logger = $logger;
    }

    /**
     * Execute observer - link the quote's affiliate tracking record to the order
     *
     * The quote's row is written earlier by SaveAffiliateToQuote. Usually the
     * row is already linked: EventRecorder links it when it records order.placed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getEvent()->getQuote();
            /** @var Order $order */
            $order = $observer->getEvent()->getOrder();

            if (!$quote || !$order || !$this->config->isEnabled((int) $order->getStoreId())) {
                return;
            }

            $quoteId = (int) $quote->getId();
            $orderId = (int) $order->getId();

            if ($quoteId && $orderId) {
                $this->trackingRepository->linkToOrder($quoteId, $orderId);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error in CopyAffiliateToOrder: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
