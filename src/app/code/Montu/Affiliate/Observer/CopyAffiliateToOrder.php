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
use Magento\Framework\Stdlib\CookieManagerInterface;
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
     * @var CookieManagerInterface
     */
    private CookieManagerInterface $cookieManager;

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
     * @param CookieManagerInterface $cookieManager
     * @param AffiliateTrackingRepositoryInterface $trackingRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CookieManagerInterface $cookieManager,
        AffiliateTrackingRepositoryInterface $trackingRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->cookieManager = $cookieManager;
        $this->trackingRepository = $trackingRepository;
        $this->logger = $logger;
    }

    /**
     * Execute observer - link affiliate tracking record to order
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            /** @var Quote $quote */
            $quote = $observer->getEvent()->getQuote();
            /** @var Order $order */
            $order = $observer->getEvent()->getOrder();

            if (!$quote || !$order) {
                return;
            }

            $quoteId = (int) $quote->getId();
            $orderId = (int) $order->getId();

            if (!$quoteId || !$orderId) {
                return;
            }

            // Check if tracking exists for quote
            $existingCode = $this->trackingRepository->getByQuoteId($quoteId);

            if ($existingCode) {
                // Link existing tracking to order
                $this->trackingRepository->linkToOrder($quoteId, $orderId);
                
                $this->logger->info(
                    '[Montu Affiliate] Linked affiliate tracking to order',
                    [
                        'quote_id' => $quoteId,
                        'order_id' => $orderId,
                        'order_increment_id' => $order->getIncrementId(),
                        'affiliate_code' => $existingCode
                    ]
                );
            } else {
                // Try to get from cookie as fallback (edge case)
                $cookieName = $this->config->getCookieName();
                $affiliateCode = $this->cookieManager->getCookie($cookieName);

                if ($affiliateCode) {
                    // Save new tracking with both quote and order
                    $this->trackingRepository->saveByQuoteId($quoteId, $affiliateCode);
                    $this->trackingRepository->linkToOrder($quoteId, $orderId);
                    
                    $this->logger->info(
                        '[Montu Affiliate] Created and linked affiliate tracking from cookie',
                        [
                            'quote_id' => $quoteId,
                            'order_id' => $orderId,
                            'affiliate_code' => $affiliateCode
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Error in CopyAffiliateToOrder: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
