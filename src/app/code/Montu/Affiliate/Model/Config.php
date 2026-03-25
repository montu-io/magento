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
}

