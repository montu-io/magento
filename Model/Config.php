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

class Config
{
    private const XML_PATH_GENERAL_ENABLED = 'montu_affiliate/general/enabled';
    private const XML_PATH_GENERAL_URL_PARAMETER = 'montu_affiliate/general/url_parameter';
    private const XML_PATH_GENERAL_COOKIE_NAME = 'montu_affiliate/general/cookie_name';
    private const XML_PATH_GENERAL_COOKIE_LIFETIME = 'montu_affiliate/general/cookie_lifetime';
    private const XML_PATH_WEBHOOK_ENABLED = 'montu_affiliate/webhook/enabled';
    private const XML_PATH_WEBHOOK_ENDPOINT_URL = 'montu_affiliate/webhook/endpoint_url';
    private const XML_PATH_WEBHOOK_KEY_ID = 'montu_affiliate/webhook/key_id';
    private const XML_PATH_WEBHOOK_SIGNING_SECRET = 'montu_affiliate/webhook/signing_secret';
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
    private const XML_PATH_EVENTS_SEND_ORDER_UPDATED = 'montu_affiliate/events/send_order_updated';
    private const XML_PATH_EVENTS_SEND_CREDITMEMO_CREATED = 'montu_affiliate/events/send_creditmemo_created';
    private const XML_PATH_FILTERS_ORDERS_TO_SEND = 'montu_affiliate/filters/orders_to_send';
    private const XML_PATH_FILTERS_COUPON_PREFIXES = 'montu_affiliate/filters/coupon_prefixes';
    private const XML_PATH_FILTERS_EXCLUDED_GROUPS = 'montu_affiliate/filters/excluded_customer_groups';
    private const XML_PATH_DATA_CUSTOMER_DATA = 'montu_affiliate/data/customer_data';
    private const XML_PATH_QUEUE_BATCH_SIZE = 'montu_affiliate/queue/batch_size';
    private const XML_PATH_QUEUE_MAX_RETRIES = 'montu_affiliate/queue/max_retries';
    private const XML_PATH_QUEUE_RETENTION_DAYS = 'montu_affiliate/queue/retention_days';
    private const XML_PATH_QUEUE_LOG_LEVEL = 'montu_affiliate/queue/log_level';
    private const XML_PATH_STOREFRONT_FALLBACK = 'montu_affiliate/storefront/fallback';
    private const XML_PATH_STOREFRONT_HIDE_OUT_OF_STOCK = 'montu_affiliate/storefront/hide_out_of_stock';

    public const ORDERS_AFFILIATE_CODE = 'affiliate_code';
    public const ORDERS_AFFILIATE_CODE_OR_COUPON = 'affiliate_code_or_coupon';
    public const ORDERS_AFFILIATE_CODE_OR_COUPON_PREFIX = 'affiliate_code_or_coupon_prefix';
    public const ORDERS_ALL = 'all';

    public const CUSTOMER_DATA_HASHED = 'hashed';
    public const CUSTOMER_DATA_NONE = 'none';
    public const CUSTOMER_DATA_FULL = 'full';

    public const FALLBACK_EMPTY = 'empty';
    public const FALLBACK_REDIRECT_HOME = 'redirect_home';

    public const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
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
     * Get the public key id issued by Montu (sent as X-Montu-Key-Id)
     *
     * @param int|null $storeId
     * @return string
     */
    public function getKeyId(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_KEY_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get the signing secret issued by Montu (decrypted). Used only to sign
     * requests; it is never sent over the network.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSigningSecret(?int $storeId = null): string
    {
        $encryptedSecret = $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_SIGNING_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Trimmed like the key id: a secret pasted with a trailing space or
        // newline would otherwise sign every request wrongly, and Montu drops
        // those deliveries after the gateway has already answered 200.
        return $encryptedSecret ? trim($this->encryptor->decrypt($encryptedSecret)) : '';
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

    /**
     * Check if order.updated events are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendOrderUpdated(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EVENTS_SEND_ORDER_UPDATED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if creditmemo.created events are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendCreditmemoCreated(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EVENTS_SEND_CREDITMEMO_CREATED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if an event type is enabled (the per-event "send" switch)
     *
     * @param string $eventType One of the EventType constants
     * @param int|null $storeId
     * @return bool
     */
    public function isEventTypeEnabled(string $eventType, ?int $storeId = null): bool
    {
        return match ($eventType) {
            EventType::ORDER_PLACED => $this->shouldSendOnOrderPlaced($storeId),
            EventType::ORDER_UPDATED => $this->shouldSendOrderUpdated($storeId),
            EventType::CREDITMEMO_CREATED => $this->shouldSendCreditmemoCreated($storeId),
            default => false,
        };
    }

    /**
     * Which orders qualify for order.placed (see the ORDERS_* constants)
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOrdersToSendMode(?int $storeId = null): string
    {
        $mode = (string) $this->scopeConfig->getValue(
            self::XML_PATH_FILTERS_ORDERS_TO_SEND,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($mode, [
            self::ORDERS_AFFILIATE_CODE,
            self::ORDERS_AFFILIATE_CODE_OR_COUPON_PREFIX,
            self::ORDERS_ALL,
        ], true) ? $mode : self::ORDERS_AFFILIATE_CODE_OR_COUPON;
    }

    /**
     * Coupon prefixes for the "prefix" mode, lower-cased
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getCouponPrefixes(?int $storeId = null): array
    {
        return array_map('strtolower', $this->splitList((string) $this->scopeConfig->getValue(
            self::XML_PATH_FILTERS_COUPON_PREFIXES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )));
    }

    /**
     * Customer group ids whose orders are never sent
     *
     * @param int|null $storeId
     * @return int[]
     */
    public function getExcludedCustomerGroups(?int $storeId = null): array
    {
        return array_map('intval', $this->splitList((string) $this->scopeConfig->getValue(
            self::XML_PATH_FILTERS_EXCLUDED_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )));
    }

    /**
     * Buyer data mode: "hashed" (default), "none" or "full"
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCustomerDataMode(?int $storeId = null): string
    {
        $mode = (string) $this->scopeConfig->getValue(
            self::XML_PATH_DATA_CUSTOMER_DATA,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($mode, [self::CUSTOMER_DATA_NONE, self::CUSTOMER_DATA_FULL], true)
            ? $mode
            : self::CUSTOMER_DATA_HASHED;
    }

    /**
     * Max events delivered per cron run
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->positiveInt(self::XML_PATH_QUEUE_BATCH_SIZE, 50);
    }

    /**
     * Delivery attempts per event, stored on the row when it is queued
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxRetries(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_QUEUE_MAX_RETRIES, 5, $storeId);
    }

    /**
     * Days to keep delivered events (0 = keep forever)
     *
     * @return int
     */
    public function getRetentionDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(
            self::XML_PATH_QUEUE_RETENTION_DAYS,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Minimum level written to var/log/montu_affiliate.log
     *
     * @return string One of LOG_LEVELS
     */
    public function getLogLevel(): string
    {
        $level = (string) $this->scopeConfig->getValue(
            self::XML_PATH_QUEUE_LOG_LEVEL,
            ScopeInterface::SCOPE_STORE
        );

        return in_array($level, self::LOG_LEVELS, true) ? $level : 'warning';
    }

    /**
     * What the storefront page does when Montu returns nothing: "empty" or
     * "redirect_home"
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorefrontFallback(?int $storeId = null): string
    {
        $fallback = (string) $this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_FALLBACK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $fallback === self::FALLBACK_REDIRECT_HOME ? $fallback : self::FALLBACK_EMPTY;
    }

    /**
     * Check if out-of-stock products are hidden from the storefront page
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldHideOutOfStock(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STOREFRONT_HIDE_OUT_OF_STOCK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Read a strictly positive integer setting, falling back to a default
     *
     * @param string $path
     * @param int $default
     * @param int|null $storeId
     * @return int
     */
    private function positiveInt(string $path, int $default, ?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : $default;
    }

    /**
     * Split a comma-separated setting into trimmed, non-empty values
     *
     * @param string $value
     * @return string[]
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
