<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Storefront Controller
 *
 * Renders the affiliate storefront page for /storefront/{code}:
 *   1. Validates the affiliate code from the URL.
 *   2. Sets the affiliate cookie server-side (same cookie the JS capture uses),
 *      so visitors arriving via this route are attributed exactly like those
 *      arriving with the ?aff= query parameter.
 *   3. Renders the page; the product grid is fed by ViewModel\Storefront, which
 *      pulls the affiliate's recommended products from Montu.
 *
 * The rendered page is declared non-cacheable (see the layout block) so Full
 * Page Cache / Fastly never serves a stale, per-affiliate page or skips the
 * cookie write.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Controller\Storefront;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\TranslateInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    /**
     * Allowed affiliate code format (mirrors the cookie capture validation).
     */
    private const CODE_PATTERN = '/^[a-zA-Z0-9_\-]{1,100}$/';

    private RequestInterface $request;
    private PageFactory $pageFactory;
    private ForwardFactory $forwardFactory;
    private CookieManagerInterface $cookieManager;
    private CookieMetadataFactory $cookieMetadataFactory;
    private Config $config;
    private LocaleResolver $localeResolver;
    private TranslateInterface $translate;
    private LoggerInterface $logger;

    /**
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param ForwardFactory $forwardFactory
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param Config $config
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        PageFactory $pageFactory,
        ForwardFactory $forwardFactory,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        Config $config,
        LocaleResolver $localeResolver,
        TranslateInterface $translate,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->forwardFactory = $forwardFactory;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->config = $config;
        $this->localeResolver = $localeResolver;
        $this->translate = $translate;
        $this->logger = $logger;
    }

    /**
     * Render the affiliate storefront page.
     *
     * @return Page|Forward
     */
    public function execute()
    {
        // If the feature is off, behave as if the route does not exist.
        if (!$this->config->isEnabled() || !$this->config->isStorefrontEnabled()) {
            return $this->notFound();
        }

        $code = (string) $this->request->getParam('code', '');

        if (!preg_match(self::CODE_PATTERN, $code)) {
            return $this->notFound();
        }

        // Capture the affiliate code into the cookie (server-side).
        $this->setAffiliateCookie($code);

        // Render the page in the configured storefront language.
        $this->applyStorefrontLocale();

        $page = $this->pageFactory->create();

        // Browser/tab title: "<configured title> · <code>", translated into the
        // storefront locale applied above so it matches the page heading.
        $title = (string) __($this->config->getStorefrontTitle());
        $page->getConfig()->getTitle()->set(trim($title . ' · ' . $code, ' ·'));

        return $page;
    }

    /**
     * Write the affiliate code to the cookie, matching the JS capture behaviour
     * (same name/lifetime, path "/", SameSite=Lax, Secure on HTTPS, readable by JS).
     *
     * Never lets an exception escape - a cookie failure must not break the page.
     *
     * @param string $code
     * @return void
     */
    private function setAffiliateCookie(string $code): void
    {
        try {
            $cookieName = $this->config->getCookieName();
            $lifetimeDays = $this->config->getCookieLifetime();

            /** @var PublicCookieMetadata $metadata */
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $metadata->setPath('/');
            $metadata->setDuration($lifetimeDays * 86400);
            $metadata->setHttpOnly(false);
            $metadata->setSameSite('Lax');

            if ($this->request->isSecure()) {
                $metadata->setSecure(true);
            }

            $this->cookieManager->setPublicCookie($cookieName, $code, $metadata);

            $this->logger->debug(
                '[Montu Affiliate] Storefront route set affiliate cookie',
                ['affiliate_code' => $code]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                '[Montu Affiliate] Could not set affiliate cookie from storefront route: '
                . $e->getMessage()
            );
        }
    }

    /**
     * Switch the request's locale + translation data to the configured storefront
     * language so the page's __() strings render translated. No-op when unset
     * (falls back to the store's default locale). Never breaks the page.
     *
     * @return void
     */
    private function applyStorefrontLocale(): void
    {
        try {
            $locale = $this->config->getStorefrontLocale();
            if ($locale === '') {
                return;
            }
            $this->localeResolver->setLocale($locale);
            $this->translate->setLocale($locale)->loadData(Area::AREA_FRONTEND, true);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[Montu Affiliate] Could not apply storefront locale: ' . $e->getMessage()
            );
        }
    }

    /**
     * Forward to the standard 404 handler.
     *
     * @return Forward
     */
    private function notFound(): Forward
    {
        $forward = $this->forwardFactory->create();
        return $forward->forward('noroute');
    }
}
