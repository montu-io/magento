<?php
/**
 * Montu Affiliate Tracking Extension - Configuration Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    private const XML_PATH_GENERAL_ENABLED = 'montu_affiliate/general/enabled';
    private const XML_PATH_GENERAL_URL_PARAMETER = 'montu_affiliate/general/url_parameter';
    private const XML_PATH_GENERAL_COOKIE_NAME = 'montu_affiliate/general/cookie_name';
    private const XML_PATH_GENERAL_COOKIE_LIFETIME = 'montu_affiliate/general/cookie_lifetime';
    private const XML_PATH_WEBHOOK_ENABLED = 'montu_affiliate/webhook/enabled';
    private const XML_PATH_WEBHOOK_ENDPOINT_URL = 'montu_affiliate/webhook/endpoint_url';
    private const XML_PATH_WEBHOOK_API_KEY = 'montu_affiliate/webhook/api_key';
    private const XML_PATH_WEBHOOK_SEND_ON_ORDER_PLACED = 'montu_affiliate/webhook/send_on_order_placed';
    private const XML_PATH_WEBHOOK_TIMEOUT = 'montu_affiliate/webhook/timeout';
    private const XML_PATH_STOREFRONT_ENABLED = 'montu_affiliate/storefront/enabled';
    private const XML_PATH_STOREFRONT_ROUTE_PATH = 'montu_affiliate/storefront/route_path';
    private const XML_PATH_STOREFRONT_TITLE = 'montu_affiliate/storefront/title';
    private const XML_PATH_STOREFRONT_PRODUCTS_ENDPOINT = 'montu_affiliate/storefront/products_endpoint';
    private const XML_PATH_STOREFRONT_PRODUCTS_LIMIT = 'montu_affiliate/storefront/products_limit';
    private const XML_PATH_STOREFRONT_LOCALE = 'montu_affiliate/storefront/locale';
    private const XML_PATH_APPEARANCE_THEME_MODE = 'montu_affiliate/appearance/theme_mode';
    private const XML_PATH_APPEARANCE_ACCENT_COLOR = 'montu_affiliate/appearance/accent_color';
    private const XML_PATH_APPEARANCE_ACCENT_TEXT_COLOR = 'montu_affiliate/appearance/accent_text_color';
    private const XML_PATH_APPEARANCE_TEXT_COLOR = 'montu_affiliate/appearance/text_color';
    private const XML_PATH_APPEARANCE_MUTED_COLOR = 'montu_affiliate/appearance/muted_color';
    private const XML_PATH_APPEARANCE_BORDER_COLOR = 'montu_affiliate/appearance/border_color';
    private const XML_PATH_APPEARANCE_SURFACE_COLOR = 'montu_affiliate/appearance/surface_color';
    private const XML_PATH_APPEARANCE_CARD_RADIUS = 'montu_affiliate/appearance/card_radius';
    private const XML_PATH_APPEARANCE_FONT_FAMILY = 'montu_affiliate/appearance/font_family';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
    }

    /**
     * Check if module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get URL parameter name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getUrlParameter(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL_URL_PARAMETER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '_m_aff';
    }

    /**
     * Get cookie name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCookieName(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL_COOKIE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '_montu_aff_ref_code';
    }

    /**
     * Get cookie lifetime in days
     *
     * @param int|null $storeId
     * @return int
     */
    public function getCookieLifetime(?int $storeId = null): int
    {
        $lifetime = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL_COOKIE_LIFETIME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (int) ($lifetime ?: 15);
    }

    /**
     * Check if webhook is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isWebhookEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WEBHOOK_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get webhook endpoint URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWebhookEndpointUrl(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_ENDPOINT_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get API key (decrypted)
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        $encryptedKey = $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encryptedKey ? $this->encryptor->decrypt($encryptedKey) : '';
    }

    /**
     * Check if should send webhook on order placed
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendOnOrderPlaced(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WEBHOOK_SEND_ON_ORDER_PLACED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get webhook timeout in seconds
     *
     * @param int|null $storeId
     * @return int
     */
    public function getWebhookTimeout(?int $storeId = null): int
    {
        $timeout = $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (int) ($timeout ?: 30);
    }

    /**
     * Check if the affiliate storefront route is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStorefrontEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STOREFRONT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the storefront URL prefix (default: "storefront" -> /storefront/{code})
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorefrontRoutePath(?int $storeId = null): string
    {
        $path = (string) $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_ROUTE_PATH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return trim($path, '/') ?: 'storefront';
    }

    /**
     * Get the storefront page heading/title
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorefrontTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'Recommended products';
    }

    /**
     * Get the Montu recommendations endpoint URL (empty until the API exists)
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorefrontProductsEndpoint(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_PRODUCTS_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the maximum number of recommended products to render
     *
     * @param int|null $storeId
     * @return int
     */
    public function getStorefrontProductsLimit(?int $storeId = null): int
    {
        $limit = $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_PRODUCTS_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (int) ($limit ?: 12);
    }

    /**
     * Get the configured storefront locale (e.g. "es_ES", "en_US").
     *
     * Empty string means "use the store's default locale".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorefrontLocale(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get current store base URL
     *
     * @return string
     */
    public function getStoreBaseUrl(): string
    {
        try {
            return $this->storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get current store code
     *
     * @return string
     */
    public function getStoreCode(): string
    {
        try {
            return $this->storeManager->getStore()->getCode();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Storefront white-label theme mode: "auto", "manual" or "off".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getThemeMode(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_THEME_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'auto';
    }

    /**
     * Configured accent (primary action) colour, or "" to inherit/sample.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getAccentColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_ACCENT_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured readable text colour on the accent, or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getAccentTextColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_ACCENT_TEXT_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured primary body text colour, or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTextColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_TEXT_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured muted/secondary text colour, or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getMutedColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_MUTED_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured border/divider colour, or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getBorderColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_BORDER_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured card/surface background colour, or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSurfaceColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_SURFACE_COLOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured card corner radius (e.g. "10px" or "0"), or "".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCardRadius(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_CARD_RADIUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Configured font-family stack, or "" to inherit the store theme font.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getFontFamily(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_APPEARANCE_FONT_FAMILY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}

