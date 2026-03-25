<?php
/**
 * Montu Affiliate Tracking Extension - Save Affiliate Code to Quote Observer
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class SaveAffiliateToQuote implements ObserverInterface
{
    private Config $config;
    private CookieManagerInterface $cookieManager;
    private AffiliateTrackingRepositoryInterface $trackingRepository;
    private LoggerInterface $logger;

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
     * Execute observer - save affiliate code from cookie to tracking table
     *
     * IMPORTANT: This observer must NEVER throw exceptions that escape,
     * as it runs inside Magento's cart transaction and would cause rollback errors.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Early return if disabled
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            // Safely get the cart - may be null
            $cart = $observer->getEvent()->getCart();
            if ($cart === null) {
                return;
            }

            // Safely get the quote - may be null or without ID
            $quote = $cart->getQuote();
            if ($quote === null || !$quote->getId()) {
                return;
            }

            $quoteId = (int) $quote->getId();

            // Check if already tracked - wrap in try/catch in case table doesn't exist
            try {
                if ($this->trackingRepository->existsForQuote($quoteId)) {
                    return;
                }
            } catch (\Exception $e) {
                // Table might not exist yet (setup:upgrade not run), skip silently
                $this->logger->debug(
                    '[Montu Affiliate] Could not check quote tracking: ' . $e->getMessage()
                );
                return;
            }

            // Get affiliate code from cookie
            $cookieName = $this->config->getCookieName();
            $affiliateCode = $this->cookieManager->getCookie($cookieName);

            if ($affiliateCode) {
                try {
                    $this->trackingRepository->saveByQuoteId($quoteId, $affiliateCode);
                    $this->logger->debug(
                        '[Montu Affiliate] Saved affiliate code from cookie',
                        ['quote_id' => $quoteId, 'affiliate_code' => $affiliateCode]
                    );
                } catch (\Exception $e) {
                    // Don't break the cart - just log the error
                    $this->logger->warning(
                        '[Montu Affiliate] Could not save affiliate tracking: ' . $e->getMessage()
                    );
                }
            }
        } catch (\Exception $e) {
            // CRITICAL: Never let exceptions escape this observer
            // It would break the entire cart/checkout functionality
            $this->logger->error(
                '[Montu Affiliate] Error in SaveAffiliateToQuote (suppressed): ' . $e->getMessage()
            );
        }
    }
}
