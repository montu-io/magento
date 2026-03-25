<?php
/**
 * Montu Affiliate Tracking Extension - ViewModel for Frontend
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Montu\Affiliate\Model\Config;

class AffiliateConfig implements ArgumentInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Check if module is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Get URL parameter name
     *
     * @return string
     */
    public function getUrlParameter(): string
    {
        return $this->config->getUrlParameter();
    }

    /**
     * Get cookie name
     *
     * @return string
     */
    public function getCookieName(): string
    {
        return $this->config->getCookieName();
    }

    /**
     * Get cookie lifetime in days
     *
     * @return int
     */
    public function getCookieLifetimeDays(): int
    {
        return $this->config->getCookieLifetime();
    }

    /**
     * Get JSON config for JavaScript
     *
     * @return string
     */
    public function getJsonConfig(): string
    {
        return json_encode([
            'urlParameter' => $this->getUrlParameter(),
            'cookieName' => $this->getCookieName(),
            'cookieLifetimeDays' => $this->getCookieLifetimeDays()
        ]);
    }
}

