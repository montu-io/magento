<?php
/**
 * Montu Affiliate Tracking Extension - Save Affiliate Code to Quote Observer
 *
 * Writes the shopper's affiliate cookie on the quote's tracking row, so
 * EventRecorder finds the code by quote id when the order is committed.
 * Registered for the storefront and the REST checkout only
 * (etc/frontend/events.xml, etc/webapi_rest/events.xml), never the admin:
 * - checkout_cart_save_after: the cart is saved with the cookie present;
 * - sales_model_service_quote_submit_before: last chance for a code that is
 *   only in the cookie (no cart save saw it), before the order exists.
 *
 * Runs in the shopper's request: a shopper without the cookie costs no query,
 * and no exception ever escapes.
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
use Montu\Affiliate\Api\AffiliateTrackingRepositoryInterface;
use Montu\Affiliate\Model\AffiliateCode;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class SaveAffiliateToQuote implements ObserverInterface
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
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $cart = $event->getCart();
            $quote = $event->getQuote() ?: ($cart ? $cart->getQuote() : null);
            if (!$quote instanceof Quote || !$quote->getId()) {
                return;
            }

            $storeId = (int) $quote->getStoreId();
            if (!$this->config->isEnabled($storeId)) {
                return;
            }

            $code = AffiliateCode::fromCookie(
                $this->cookieManager->getCookie($this->config->getCookieName($storeId))
            );
            if ($code === null) {
                return;
            }

            // The first code captured on a cart is kept.
            $quoteId = (int) $quote->getId();
            if (!$this->trackingRepository->existsForQuote($quoteId)) {
                $this->trackingRepository->saveByQuoteId($quoteId, $code);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error saving the affiliate code on the quote (suppressed): ' . $e->getMessage()
            );
        }
    }
}
